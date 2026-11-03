{{-- Aviso prévio de desativação de chave de API por inatividade.
     Só identificadores PÚBLICOS da chave — a secreta nunca sai do momento da
     criação. --}}
<x-email::layouts.kit
    :title="__('mail.api_key_inactivity.subject', ['platform' => platform()->name])"
    :preheader="__('mail.api_key_inactivity.preheader', ['days' => $expiresInDays])"
>
    <x-email::heading>{{ __('mail.api_key_inactivity.heading') }}</x-email::heading>

    <x-email::text>{{ __('mail.api_key_inactivity.intro') }}</x-email::text>

    <x-email::panel>
        <x-email::field :label="__('mail.api_key_inactivity.name_label')" :value="$keyName" />
        <x-email::field :label="__('mail.api_key_inactivity.code_label')" :value="$keyPublicCode" mono />
        <x-email::field :label="__('mail.api_key_inactivity.key_label')" :value="$keyPublicKey" mono last />
    </x-email::panel>

    <x-email::rule :space="24" />

    <x-email::text>{{ __('mail.api_key_inactivity.expires', ['days' => $expiresInDays]) }}</x-email::text>

    <x-email::text>{{ __('mail.api_key_inactivity.action') }}</x-email::text>

    <x-email::button :url="route('panel.api-keys')">{{ __('mail.api_key_inactivity.cta') }}</x-email::button>

    <x-email::rule :space="24" />

    <x-email::notice>{{ __('mail.api_key_inactivity.ignore') }}</x-email::notice>
</x-email::layouts.kit>
