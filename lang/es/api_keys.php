<?php

declare(strict_types=1);

// Cadenas del motor de API Keys + Tenancy (es). Siempre via __().

return [

    // Autenticación de la API (ResolveTenant).
    'auth' => [
        // Mensaje ÚNICO y deliberadamente genérico: no revela si la clave
        // pública existe, si la secreta falló o si la clave expiró.
        'invalid' => 'Credenciales de API ausentes, inválidas o expiradas.',
    ],

    // Autorización por scope (middleware scope:recurso:accion).
    'scopes' => [
        'denied' => 'Esta clave de API no tiene permiso para el alcance ":scope".',
        'invalid_format' => 'Cada alcance debe estar en el formato "recurso:accion" (ej.: customers:read, pix:create, withdrawals:*).',
    ],

    // Operaciones del motor de claves.
    'keys' => [
        'created' => 'Clave de API creada. Guarda la clave secreta ahora — no se mostrará de nuevo.',
        'rotated' => 'Clave rotada. Guarda la nueva clave secreta ahora — no se mostrará de nuevo.',
        'revoked' => 'Clave de API revocada con éxito.',
        'not_rotatable' => 'Solo las claves activas pueden rotarse.',
        'projects_synced' => 'Proyectos vinculados a la clave con éxito.',
    ],

    // Proyectos (capa organizacional).
    'projects' => [
        'created' => 'Proyecto creado con éxito.',
        'updated' => 'Proyecto actualizado con éxito.',
        'deleted' => 'Proyecto eliminado con éxito.',
        'invalid' => 'Uno o más proyectos informados no existen en tu cuenta.',
        'account_key_required' => 'Esta clave está vinculada a proyectos y solo actúa sobre ellos. Usa una clave sin vínculo (toda la cuenta) para crear proyectos y gestionar claves.',
    ],

];
