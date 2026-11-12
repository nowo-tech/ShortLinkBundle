<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Presentation\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * CSRF-only form of the state-changing row actions (disable, enable, delete; REQ-TWIG-005: a Form Type rendered by
 * UiKit's action partial, never a raw `<form>`). One token id per action (`csrf_token_id`), POST only, field `_token`.
 * A hidden marker field `confirm` makes the nameless form "submitted" (Symfony submits a nameless form only when one
 * of its fields is in the request); it is a plain hidden child, added without profile defaults.
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig('short_link')]
final class ShortLinkActionType extends AbstractType
{
    use FormOptionsTrait;

    /**
     * Adds the hidden marker.
     *
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('confirm', HiddenType::class, ['mapped' => false, 'data' => '1']);
    }

    /**
     * POST, CSRF on, `csrf_token_id` required.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'method'             => 'POST',
            'csrf_protection'    => true,
            'csrf_field_name'    => '_token',
            'allow_extra_fields' => true,
        ]);
        $resolver->setRequired('csrf_token_id');
    }

    /**
     * Nameless: the only field is `_token`.
     */
    public function getBlockPrefix(): string
    {
        return '';
    }
}
