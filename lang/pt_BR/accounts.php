<?php

declare(strict_types=1);

return [
    'personal_account' => 'Conta pessoal',

    'roles' => [
        'owner' => 'Dono',
        'admin' => 'Administrador',
        'member' => 'Membro',
    ],

    'context' => [
        'missing' => 'Consulta de :model sem conta atual. Dados de conta só são lidos com uma conta definida (sessão do painel ou chave de API) ou em modo sistema explícito: Accounts::asSystem(\'motivo\', fn () => ...) ou Accounts::actingAs($conta, fn () => ...).',
        'system_write_without_account' => 'Em modo sistema, :model precisa ser gravado com a conta informada (account_id).',
        'cross_account_write' => 'Gravação de :model numa conta que não é a atual foi recusada.',
        'account_change' => 'A conta de :model não muda depois de gravada.',
    ],

    'ownership' => [
        'second_owner' => 'A conta já tem um dono. A propriedade muda por transferência.',
        'role_change' => 'O papel de dono não é concedido nem retirado por aqui. A propriedade muda por transferência.',
        'owner_leaves' => 'O dono não sai da conta. Transfira a propriedade antes.',
        'moves_account' => 'Um vínculo de membro não muda de conta.',
        'invalid_transfer' => 'A transferência precisa sair do dono atual para outro membro da conta.',
    ],

    'members' => [
        'already_member' => 'Esta pessoa já é membro da conta.',
        'cannot_change_role' => 'Seu papel não permite mudar o papel desta pessoa. O dono não é alterado por aqui, e só o dono mexe em outro administrador.',
        'cannot_remove' => 'Seu papel não permite remover esta pessoa. O dono não é removido, e só o dono remove um administrador.',
        'owner_cannot_leave' => 'O dono não sai da conta. Transfira a propriedade antes.',
    ],

    'deletion' => [
        'owner_has_members' => '{1} Esta pessoa é dona de uma conta com outros membros (:accounts). Transfira a propriedade antes de excluí-la.|[2,*] Esta pessoa é dona de contas com outros membros (:accounts). Transfira a propriedade antes de excluí-la.',
    ],

    'authorization' => [
        'denied' => 'Seu papel nesta conta não permite esta ação.',
        'not_member' => 'Você não é membro desta conta.',
        // Motivo gravado na trilha (`denied`) quando a tela procura, na conta
        // atual, um recurso que não está nela — a resposta continua o 404 comum.
        'not_found' => 'Recurso não encontrado na conta atual (inexistente ou de outra conta).',
    ],

    'account' => [
        'name_invalid' => 'Informe um nome de até :max caracteres.',
        'limit_reached' => 'Você chegou ao limite de :max contas de empresa como dono.',
        'personal_rename' => 'A conta pessoal usa o seu nome — ela não é renomeada por aqui.',
        'personal_delete' => 'A conta pessoal não é excluída por aqui: ela sai junto com a sua conta de acesso.',
    ],

    'sensitive' => [
        'required' => 'Esta ação precisa da confirmação com a senha de transação e o código enviado por e-mail.',
    ],

    'transfer' => [
        'personal' => 'A conta pessoal não é transferida: ela é da pessoa.',
        'self' => 'Escolha outro membro da conta para receber a propriedade.',
    ],

    'switch' => [
        'switched' => 'Agora você está na conta :account.',
    ],

    'invitations' => [
        'status' => [
            'pending' => 'Pendente',
            'accepted' => 'Aceito',
            'revoked' => 'Revogado',
            'declined' => 'Recusado',
            'expired' => 'Expirado',
        ],
        'unavailable' => [
            'not_found' => 'Este convite não existe ou o link está incompleto. Peça um novo convite a quem convidou você.',
            'expired' => 'Este convite expirou. Peça a quem convidou você para reenviá-lo.',
            'revoked' => 'Este convite foi cancelado por quem o enviou.',
            'accepted' => 'Este convite já foi usado.',
            'declined' => 'Este convite foi recusado.',
            'wrong_email' => 'Este convite foi enviado para outro e-mail. Saia e entre com a conta desse e-mail para aceitá-lo.',
            'already_member' => 'Você já é membro desta conta.',
            'has_account' => 'Este e-mail já tem uma conta. Entre com ela para aceitar o convite.',
        ],
        'owner_role' => 'O convite é para administrador ou membro. A propriedade só muda por transferência.',
        'email_invalid' => 'Informe um e-mail válido.',
        'already_member' => 'Esta pessoa já é membro da conta.',
        'already_pending' => 'Já existe um convite pendente para este e-mail. Reenvie o convite em vez de criar outro.',
        'too_many_pending' => 'A conta chegou ao limite de :max convites pendentes. Revogue algum ou espere serem aceitos.',
        'throttled' => 'Muitos convites em pouco tempo. Tente de novo em :minutes min.',
        'not_resendable' => 'Só um convite pendente ou expirado pode ser reenviado.',
        'not_revocable' => 'Só um convite pendente ou expirado pode ser revogado.',
        'accepted' => 'Convite aceito. Bem-vindo à conta :account.',
        'declined' => 'Convite recusado.',
        'fields' => [
            'name' => 'nome',
            'password' => 'senha',
        ],
    ],
];
