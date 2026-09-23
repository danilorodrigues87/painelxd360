<?php

// Rotas públicas e login
include __DIR__.'/admin/public.php';
include __DIR__.'/admin/autentication.php';

// Painel cliente XD360 (mínimo)
include __DIR__.'/admin/home.php';
include __DIR__.'/admin/produtos.php';
include __DIR__.'/admin/assinatura.php';
include __DIR__.'/admin/escola.php';
include __DIR__.'/admin/users.php';
include __DIR__.'/admin/perfil.php';
include __DIR__.'/admin/suporte.php';

// Webhooks Mercado Pago (SaaS)
include __DIR__.'/admin/pagamentos.php';
