<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Presentation\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * GET range of the statistics page (custom range, at most 366 UTC days): query keys `from` / `to` (`Y-m-d`).
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig('short_link')]
final class StatsRangeType extends AbstractType
{
    use FormOptionsTrait;

    /**
     * Adds the two dates.
     *
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->withBuilder($builder, function (): void {
            foreach (['from' => 'ui.stats.from', 'to' => 'ui.stats.to'] as $name => $label) {
                $this->addTypedField($name, DateType::class, [
                    'label'          => $label,
                    'help'           => false,
                    'placeholder'    => false,
                    'required'       => false,
                    'widget'         => 'single_text',
                    'html5'          => true,
                    'input'          => 'datetime_immutable',
                    'model_timezone' => 'UTC',
                    'view_timezone'  => 'UTC',
                ]);
            }
        });
    }

    /**
     * GET, no CSRF, extra query keys allowed.
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
     * Nameless: query keys `from` and `to`.
     */
    public function getBlockPrefix(): string
    {
        return '';
    }
}
