{{-- AVISO DE CHAVE ÓRFÃ: quem criou chaves de API saiu da conta e elas
     CONTINUAM VALENDO (são da conta). Só identificadores PÚBLICOS de cada
     chave — nunca a secreta. O botão abre a tela de chaves JÁ NA CONTA CERTA
     (URL assinada: ninguém monta um link que troca a conta de outra pessoa). --}}
@php
    $reviewUrl = url(\Illuminate\Support\Facades\URL::signedRoute('accounts.open', ['account' => $accountUuid, 'to' => 'api-keys'], absolute: false));
@endphp
<x-email::layouts.kit
    :title="trans_choice('mail.orphaned_api_keys.subject', count($keys), ['platform' => platform()->name, 'account' => $accountName])"
    :preheader="trans_choice('mail.orphaned_api_keys.preheader', count($keys), ['count' => count($keys), 'account' => $accountName])"
>
    <x-email::heading>{{ trans_choice('mail.orphaned_api_keys.heading', count($keys)) }}</x-email::heading>

    <x-email::text>{{ trans_choice($deleted ? 'mail.orphaned_api_keys.intro_deleted' : ($removed ? 'mail.orphaned_api_keys.intro_removed' : 'mail.orphaned_api_keys.intro_left'), count($keys), ['name' => $departedName, 'account' => $accountName]) }}</x-email::text>

    @foreach ($keys as $key)
        <x-email::panel>
            <x-email::field :label="__('mail.orphaned_api_keys.name_label')" :value="$key['name']" />
            <x-email::field :label="__('mail.orphaned_api_keys.code_label')" :value="$key['code']" mono />
            <x-email::field :label="__('mail.orphaned_api_keys.key_label')" :value="$key['public_key']" mono last />
        </x-email::panel>
    @endforeach

    <x-email::rule :space="24" />

    <x-email::text>{{ trans_choice('mail.orphaned_api_keys.still_valid', count($keys)) }}</x-email::text>

    <x-email::button :url="$reviewUrl">{{ __('mail.orphaned_api_keys.cta') }}</x-email::button>

    <x-email::rule />

    <x-email::text muted last>{{ __('mail.orphaned_api_keys.why') }}</x-email::text>
</x-email::layouts.kit>
