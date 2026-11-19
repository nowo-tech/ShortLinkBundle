<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Unit\Infrastructure\Http;

use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Nowo\ShortLinkBundle\Infrastructure\Http\ShortLinkConflictListener;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\CommitMark;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\DelayedMessageHandlingException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Throwable;

#[CoversClass(ShortLinkConflictListener::class)]
final class ShortLinkConflictListenerTest extends TestCase
{
    private const ADMIN = 'Nowo\\ShortLinkBundle\\Presentation\\Controller\\ShortLinkUiController::edit';

    private const PUBLIC = 'Nowo\\ShortLinkBundle\\Infrastructure\\Http\\RedirectController::__invoke';

    /**
     * @return iterable<string, array{0: Throwable}>
     */
    public static function retrySafeConflicts(): iterable
    {
        yield 'deadlock (MySQL 1213)' => [new DeadlockException(self::driverError('Deadlock found', '40001', 1213), null)];
        yield 'lock wait timeout (MySQL 1205)' => [new LockWaitTimeoutException(self::driverError('Lock wait timeout', 'HY000', 1205), null)];
        yield 'serialization failure (PostgreSQL 40001)' => [new DriverException(self::driverError('could not serialize', '40001'), null)];
        yield 'deadlock (PostgreSQL 40P01)' => [new DriverException(self::driverError('deadlock detected', '40P01'), null)];
        yield 'lock timeout (PostgreSQL 55P03)' => [new DriverException(self::driverError('lock timeout', '55P03'), null)];
        yield 'MariaDB 1020' => [new DriverException(self::driverError('Record has changed', 'HY000', 1020), null)];
        yield 'the runner\'s TransientStorageFailure' => [TransientStorageFailure::fromPrevious(new RuntimeException('x'), 1)];
        yield 'wrapped by the kernel' => [new RuntimeException('wrapped', 0, new DeadlockException(self::driverError('Deadlock', '40001', 1213), null))];
    }

    #[DataProvider('retrySafeConflicts')]
    public function testALockConflictWithNothingCommittedAnswers409Conflict001WithRetryAfter(Throwable $failure): void
    {
        $answer = $this->answer(new ShortLinkConflictListener(), $failure);

        self::assertSame(409, $answer->getStatusCode());
        self::assertSame(['X-Conflict-Code' => ConflictCode::RETRY_SAFE, 'Retry-After' => '1'], $answer->getHeaders());
        self::assertSame(ShortLinkConflictListener::RETRY_SAFE_MESSAGE, $answer->getMessage());
        self::assertSame($failure, $answer->getPrevious(), 'the storage error stays for the logs');
    }

    public function testTheRunnerHintAndTheHostPolicyAreUsed(): void
    {
        $listener = new ShortLinkConflictListener(new ConflictResponsePolicy(503, 7));

        $hinted = $this->answer($listener, new RuntimeException('w', 0, TransientStorageFailure::fromPrevious(new RuntimeException('x'), 4)));
        self::assertSame(503, $hinted->getStatusCode());
        self::assertSame('4', $hinted->getHeaders()['Retry-After']);

        self::assertSame('7', $this->answer($listener, TransientStorageFailure::fromPrevious(new RuntimeException('x'), 0))->getHeaders()['Retry-After'], 'a zero hint is no hint');
        self::assertSame('7', $this->answer($listener, new DeadlockException(self::driverError('Deadlock', '40001', 1213), null))->getHeaders()['Retry-After']);
    }

    public function testACommitOfTheBundleConnectionEarlierInTheRequestAnswersConflict002WithoutRetryAfter(): void
    {
        $ledger   = new CommitLedger();
        $listener = new ShortLinkConflictListener(null, $ledger);
        $request  = $this->request();
        $listener->onRequest($this->requestEvent($request, HttpKernelInterface::MAIN_REQUEST));
        self::assertInstanceOf(CommitMark::class, $request->attributes->get(ShortLinkConflictListener::MARK_ATTRIBUTE));

        $failure = TransientStorageFailure::fromPrevious(new RuntimeException('x'), 1);
        self::assertSame(ConflictCode::RETRY_SAFE, $this->answer($listener, $failure, $request)->getHeaders()['X-Conflict-Code'], 'nothing committed yet');

        $ledger->record();
        $answer = $this->answer($listener, $failure, $request);
        self::assertSame(409, $answer->getStatusCode());
        self::assertSame(['X-Conflict-Code' => ConflictCode::COMMITTED], $answer->getHeaders());
        self::assertSame(ShortLinkConflictListener::COMMITTED_MESSAGE, $answer->getMessage());
    }

    public function testASubRequestReadsTheMarkOfTheMainRequest(): void
    {
        $ledger   = new CommitLedger();
        $stack    = new RequestStack();
        $listener = new ShortLinkConflictListener(null, $ledger, $stack);
        $main     = $this->request();
        $stack->push($main);
        $listener->onRequest($this->requestEvent($main, HttpKernelInterface::MAIN_REQUEST));
        $sub = $this->request();
        $listener->onRequest($this->requestEvent($sub, HttpKernelInterface::SUB_REQUEST));
        self::assertNull($sub->attributes->get(ShortLinkConflictListener::MARK_ATTRIBUTE));

        $ledger->record();

        self::assertSame(ConflictCode::COMMITTED, $this->answer($listener, TransientStorageFailure::fromPrevious(new RuntimeException('x')), $sub)->getHeaders()['X-Conflict-Code']);
    }

    public function testWithoutALedgerNoMarkIsTaken(): void
    {
        $request = $this->request();
        (new ShortLinkConflictListener())->onRequest($this->requestEvent($request, HttpKernelInterface::MAIN_REQUEST));

        self::assertFalse($request->attributes->has(ShortLinkConflictListener::MARK_ATTRIBUTE));
    }

    public function testTransactionDoctrineRequestMarkAndCommittedConflictsAnswerConflict002(): void
    {
        $listener = new ShortLinkConflictListener();
        $ledger   = new CommitLedger();
        $request  = $this->request();
        $request->attributes->set(CommitMark::REQUEST_ATTRIBUTE, new CommitMark($ledger));
        $ledger->record();
        self::assertSame(['X-Conflict-Code' => ConflictCode::COMMITTED], $this->answer($listener, TransientStorageFailure::fromPrevious(new RuntimeException('x')), $request)->getHeaders(), 'host commit earlier in the request');

        $committed = new ConcurrencyConflict(1, TransientFailureKind::Deadlock, 1, new RuntimeException('x'), true);
        self::assertSame(['X-Conflict-Code' => ConflictCode::COMMITTED], $this->answer($listener, $committed)->getHeaders());

        $lostSavepoint = new DriverException(self::driverError('SAVEPOINT DOCTRINE_2 does not exist', '42000', 1305), null);
        self::assertSame(['X-Conflict-Code' => ConflictCode::COMMITTED], $this->answer($listener, $lostSavepoint)->getHeaders(), 'commit outcome unknown');
    }

    public function testAUniqueViolationIsNotMapped(): void
    {
        foreach ([self::ADMIN, self::PUBLIC] as $controller) {
            $failure = new RuntimeException('w', 0, new UniqueConstraintViolationException(self::driverError('Duplicate entry', '23000', 1062), null));
            $event   = $this->event($failure, $this->request($controller));
            (new ShortLinkConflictListener())->onException($event);

            self::assertSame($failure, $event->getThrowable(), 'a unique violation is not a lock conflict');
        }
    }

    public function testAMessengerFailureAfterAHandlerRanAnswersConflict002(): void
    {
        $listener = new ShortLinkConflictListener();
        $deadlock = new DeadlockException(self::driverError('Deadlock', '40001', 1213), null);

        $handled = new HandlerFailedException((new Envelope(new stdClass()))->with(new HandledStamp(null, 'first')), [$deadlock]);
        self::assertSame(['X-Conflict-Code' => ConflictCode::COMMITTED], $this->answer($listener, $handled)->getHeaders(), 'a handler already ran');

        $none = new HandlerFailedException(new Envelope(new stdClass()), [$deadlock]);
        self::assertSame(ConflictCode::RETRY_SAFE, $this->answer($listener, $none)->getHeaders()['X-Conflict-Code'], 'no handler ran');

        $delayed = new DelayedMessageHandlingException([$deadlock]);
        self::assertSame(['X-Conflict-Code' => ConflictCode::COMMITTED], $this->answer($listener, new RuntimeException('w', 0, $delayed))->getHeaders());
    }

    public function testALockConflictOnTheRedirectAnswers409Conflict001(): void
    {
        $answer = $this->answer(new ShortLinkConflictListener(), new DeadlockException(self::driverError('Deadlock', '40001', 1213), null), $this->request(self::PUBLIC));

        self::assertSame(['X-Conflict-Code' => ConflictCode::RETRY_SAFE, 'Retry-After' => '1'], $answer->getHeaders());
    }

    /**
     * @return iterable<string, array{0: Throwable}>
     */
    public static function notConflicts(): iterable
    {
        yield 'lost connection' => [new ConnectionLost(self::driverError('MySQL server has gone away', '08S01', 2006), null)];
        yield 'other storage error' => [new DriverException(self::driverError('syntax error', '42000', 1064), null)];
        yield 'domain error' => [new RuntimeException('nope', 1213)];
    }

    #[DataProvider('notConflicts')]
    public function testOtherFailuresAreNotMapped(Throwable $failure): void
    {
        $event = $this->event($failure);
        (new ShortLinkConflictListener())->onException($event);

        self::assertSame($failure, $event->getThrowable());
    }

    public function testHostRoutesKeepTheHostErrorHandling(): void
    {
        $failure = new DeadlockException(self::driverError('Deadlock', '40001', 1213), null);
        foreach (['App\\Controller\\HomeController::index', null, ['not', 'a string']] as $controller) {
            $request = Request::create('/');
            $request->attributes->set('_controller', $controller);
            $event = $this->event($failure, $request);
            (new ShortLinkConflictListener())->onException($event);

            self::assertSame($failure, $event->getThrowable());
        }
    }

    private static function driverError(string $message, ?string $sqlState, int $code = 0): AbstractException
    {
        return new class($message, $sqlState, $code) extends AbstractException {
        };
    }

    private function request(string $controller = self::ADMIN): Request
    {
        $request = Request::create('/short-links/1/edit', 'POST');
        $request->attributes->set('_controller', $controller);

        return $request;
    }

    private function requestEvent(Request $request, int $type): RequestEvent
    {
        return new RequestEvent($this->createStub(HttpKernelInterface::class), $request, $type);
    }

    private function event(Throwable $failure, ?Request $request = null): ExceptionEvent
    {
        return new ExceptionEvent($this->createStub(HttpKernelInterface::class), $request ?? $this->request(), HttpKernelInterface::MAIN_REQUEST, $failure);
    }

    private function answer(ShortLinkConflictListener $listener, Throwable $failure, ?Request $request = null): HttpException
    {
        $event = $this->event($failure, $request);
        $listener->onException($event);
        $answer = $event->getThrowable();
        self::assertInstanceOf(HttpException::class, $answer);

        return $answer;
    }
}
