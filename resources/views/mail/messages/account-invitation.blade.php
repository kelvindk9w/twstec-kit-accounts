{{-- Convite para uma conta. UM CTA (abrir o convite) e a URL repetida em texto
     logo abaixo (cliente corporativo reescreve href e leitor de texto puro não
     clica). O MESMO e-mail para quem já tem conta e para quem não tem: a tela
     do link decide entre entrar e criar a conta. O token só existe aqui e no
     link — no banco, só o hash. --}}
@php
    $acceptUrl = route('invitations.show', $token);
@endphp
<x-email::layouts.kit
    :title="__('mail.account_invitation.subject', ['platform' => platform()->name, 'account' => $accountName])"
    :preheader="__('mail.account_invitation.preheader', ['inviter' => $inviterName, 'account' => $accountName])"
>
    <x-email::heading>{{ __('mail.account_invitation.heading', ['account' => $accountName]) }}</x-email::heading>

    <x-email::text>{{ __('mail.account_invitation.intro', ['inviter' => $inviterName, 'account' => $accountName, 'platform' => platform()->name]) }}</x-email::text>

    <x-email::panel>
        <x-email::field :label="__('mail.account_invitation.account_label')" :value="$accountName" />
        <x-email::field :label="__('mail.account_invitation.role_label')" :value="$roleLabel" />
        <x-email::field :label="__('mail.account_invitation.expires_label')" :value="$expiresAt->locale(app()->getLocale())->translatedFormat('d M Y H:i')" last />
    </x-email::panel>

    <x-email::rule :space="24" />

    <x-email::button :url="$acceptUrl">{{ __('mail.account_invitation.action') }}</x-email::button>

    <x-email::text>{{ __('mail.account_invitation.how') }}</x-email::text>

    <x-email::text muted>{{ __('mail.account_invitation.fallback') }}</x-email::text>
    <x-email::text muted>{{ $acceptUrl }}</x-email::text>

    <x-email::rule />

    <x-email::text muted last>{{ __('mail.account_invitation.ignore') }}</x-email::text>
</x-email::layouts.kit>
