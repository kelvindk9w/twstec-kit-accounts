<?php

declare(strict_types=1);

// E-mails de contas e chaves de API (pt-BR): os assuntos, montados pelas
// classes de e-mail do pacote twstec/kit-accounts, e os textos do corpo
// (resources/views/mail/messages do pacote). O aplicativo vence: a mesma chave
// no lang/ dele prevalece (ver docs/personalizacao.md).

return [

    'api_key_inactivity' => [
        'subject' => ':platform — Sua chave de API será desativada por inatividade',

        // Aviso prévio de expiração de chave de API por inatividade.
        'preheader' => 'Uma chave sem uso será desativada em :days dias.',
        'heading' => 'Uma chave de API sua está prestes a ser desativada',
        'intro' => 'A chave abaixo está sem uso e será desativada automaticamente por inatividade.',
        'name_label' => 'Nome da chave',
        'code_label' => 'Código público',
        'key_label' => 'Chave pública',
        'expires' => 'A desativação acontece em :days dias.',
        'action' => 'Para mantê-la ativa, basta fazer uma requisição autenticada com ela. Se não precisar mais dela, recomendamos revogá-la no painel.',
        'cta' => 'Abrir minhas chaves',
        'ignore' => 'Se você não reconhece esta chave, revogue-a imediatamente e troque suas credenciais.',
    ],

    'account_invitation' => [
        'subject' => ':platform — Convite para a conta :account',

        // Convite para uma conta (twstec/kit-accounts). O assunto é do pacote.
        'preheader' => ':inviter convidou você para a conta :account.',
        'heading' => 'Você foi convidado para a conta :account',
        'intro' => ':inviter convidou você para trabalhar na conta :account em :platform.',
        'account_label' => 'Conta',
        'role_label' => 'Papel',
        'expires_label' => 'Válido até',
        'action' => 'Abrir o convite',
        'how' => 'Se você ainda não tem acesso à plataforma, crie o seu na própria tela do convite. Se já tem, entre e aceite.',
        'fallback' => 'Se o botão não funcionar, copie e cole este endereço no navegador:',
        'ignore' => 'Se você não esperava este convite, ignore este e-mail: sem o aceite, nada acontece.',
    ],

    'orphaned_api_keys' => [
        'subject' => '{1} :platform — Uma chave de API da conta :account ficou sem quem a criou|[2,*] :platform — Chaves de API da conta :account ficaram sem quem as criou',

        // Aviso de chave órfã (twstec/kit-accounts). O assunto é do pacote.
        'preheader' => '{1} :count chave de API da conta :account continua valendo.|[2,*] :count chaves de API da conta :account continuam valendo.',
        'heading' => '{1} Uma chave de API ficou sem quem a criou|[2,*] Chaves de API ficaram sem quem as criou',
        'intro_removed' => '{1} :name foi removido da conta :account e tinha criado a chave abaixo.|[2,*] :name foi removido da conta :account e tinha criado as chaves abaixo.',
        'intro_deleted' => '{1} O acesso de :name à plataforma foi excluído, e essa pessoa tinha criado a chave abaixo na conta :account.|[2,*] O acesso de :name à plataforma foi excluído, e essa pessoa tinha criado as chaves abaixo na conta :account.',
        'intro_left' => '{1} :name saiu da conta :account e tinha criado a chave abaixo.|[2,*] :name saiu da conta :account e tinha criado as chaves abaixo.',
        'name_label' => 'Nome da chave',
        'code_label' => 'Código público',
        'key_label' => 'Chave pública',
        'still_valid' => '{1} A chave é da conta, não da pessoa: ela continua funcionando. Se quem a usava não deve mais ter acesso, rotacione-a ou revogue-a.|[2,*] As chaves são da conta, não da pessoa: elas continuam funcionando. Se quem as usava não deve mais ter acesso, rotacione-as ou revogue-as.',
        'cta' => 'Revisar as chaves da conta',
        'why' => 'Você recebe este aviso porque é dono ou administrador da conta.',
    ],

];
