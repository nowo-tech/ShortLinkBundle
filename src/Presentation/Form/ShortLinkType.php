<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Presentation\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Create / edit form of a link (contract §11): target URL, custom code (create only, when the kind allows it), title
 * and expiry. A FormKit Type on the `short_link` profile (REQ-TWIG-005 rule 11). It never maps `system` or `kind`:
 * the UI creates `manual` links and the controller builds the command, so an extra field in the request is refused
 * (`allow_extra_fields: false`). The values are checked by the manager's value objects; the controller turns their
 * refusals into field errors.
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig('short_link')]
final class ShortLinkType extends AbstractType
{
    use FormOptionsTrait;

    /**
     * Adds the fields.
     *
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->withBuilder($builder, function () use ($options): void {
            $this->addTypedField('target', UrlType::class, [
                'label'            => 'ui.form.target',
                'help'             => 'ui.form.target_help',
                'placeholder'      => false,
                'default_protocol' => null,
                'attr'             => ['maxlength' => 2048, 'autocomplete' => 'url', 'inputmode' => 'url'],
            ]);
            if ($options['custom_code'] === true) {
                $this->addTypedField('customCode', TextType::class, [
                    'label'       => 'ui.form.custom_code',
                    'help'        => 'ui.form.custom_code_help',
                    'placeholder' => false,
                    'required'    => false,
                    'attr'        => ['maxlength' => 32, 'autocomplete' => 'off', 'spellcheck' => 'false'],
                ]);
            }
            $this->addTypedField('title', TextType::class, [
                'label'       => 'ui.form.title',
                'help'        => false,
                'placeholder' => false,
                'required'    => false,
                'attr'        => ['maxlength' => 255],
            ]);
            $this->addTypedField('expiresAt', DateTimeType::class, [
                'label'          => 'ui.form.expires_at',
                'help'           => 'ui.form.expires_at_help',
                'placeholder'    => false,
                'required'       => false,
                'widget'         => 'single_text',
                'html5'          => true,
                'with_seconds'   => false,
                'input'          => 'datetime_immutable',
                'model_timezone' => 'UTC',
            ]);
        });
    }

    /**
     * Array data, CSRF token `nowo_short_link_form`, `custom_code` (whether the code field is shown).
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'custom_code'        => false,
            'csrf_protection'    => true,
            'csrf_token_id'      => 'nowo_short_link_form',
            'allow_extra_fields' => false,
            'translation_domain' => 'NowoShortLinkBundle',
        ]);
        $resolver->setAllowedTypes('custom_code', 'bool');
    }

    /**
     * Field names `short_link[...]`.
     */
    public function getBlockPrefix(): string
    {
        return 'short_link';
    }
}
