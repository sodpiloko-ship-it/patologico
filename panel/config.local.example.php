<?php
// EJEMPLO. Copia este archivo a  panel/data/config.local.php  EN EL HOST (Hostinger hPanel),
// pon los correos del fundador y un token largo aleatorio. data/ está gitignored: nunca llega al repo.
// El MISMO 'sync_token' debe ir en PATO: secrets/panel-tokens.json -> {"patologicos": "<ese token>"}.
return [
    'admins' => ['sodpiloko@gmail.com'],          // correos que pueden entrar al panel
    'sync_token' => 'CAMBIA-ESTO-por-un-token-largo-aleatorio-de-40+chars',
];
