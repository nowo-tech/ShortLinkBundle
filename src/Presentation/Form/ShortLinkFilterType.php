<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Presentation\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkState;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SearchType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * GET filters of the list (REQ-TWIG-005 rule 6): search (code, title or target host) and state. Nameless, so the
 * query keys stay `q` / `state` (bookmarkable); the keyset cursor `after` travels as an extra field.
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig('short_link')]
final class ShortLinkFilterType extends AbstractType
{
    use FormOptionsTrait;

    /**
     * Adds the fields.
     *
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->withBuilder($builder, function (): void {
            $this->addTypedField('q', SearchType::class, [
                'label'       => 'ui.filter.search',
                'help'        => false,
                'required'    => false,
                'placeholder' => false,
                'attr'        => ['maxlength' => 100, 'placeholder' => 'ui.filter.search_placeholder'],
            ]);
            $this->addTypedField('state', ChoiceType::class, [
                'label'       => 'ui.filter.state',
                'help'        => false,
                'required'    => false,
                'placeholder' => 'ui.filter.any_state',
                'choices'     => [
                    'ui.state.active'   => ShortLinkState::Active->value,
                    'ui.state.disabled' => ShortLinkState::Disabled->value,
                    'ui.state.expired'  => ShortLinkState::Expired->value,
                ],
            ]);
        });
    }

    /**
     * GET, no CSRF, extra query keys allowed (cursor).
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'method'             => 'GET',
            'csrf_protection'    => false,
            'allow_extra_fields' => true,
            'translation_domain' => 'NowoShortLinkBundle',
        ]);
    }

    /**
     * Nameless: query keys `q` and `state`.
     */
    public function getBlockPrefix(): string
    {
        return '';
    }
}
