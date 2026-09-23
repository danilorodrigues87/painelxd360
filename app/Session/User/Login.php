<?php

namespace App\Session\User;

use \App\Model\Entity\User as EntityUser;
use \App\Model\Entity\ClientesAssinantes;
use \App\Common\Helpers\ModuleGateHelper;
use \App\Common\Helpers\MasterGateHelper;

class Login {

    private static $sessionSynced = false;

    //INICIA A SESSÃO COM TEMPO ESTENDIDO
    private static function init() {
        if (session_status() != PHP_SESSION_ACTIVE) {
            $tempo_sessao = 86400;
            ini_set('session.gc_maxlifetime', (string)$tempo_sessao);

            $cookiePath = '/';
            if (defined('URL')) {
                $path = parse_url((string)URL, PHP_URL_PATH);
                if (is_string($path) && $path !== '' && $path !== '/') {
                    $cookiePath = rtrim($path, '/').'/';
                }
            }

            session_set_cookie_params([
                'lifetime' => $tempo_sessao,
                'path'     => $cookiePath,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);

            $caminhoSessao = __DIR__.'/../../sessions';
            if (!is_dir($caminhoSessao)) {
                mkdir($caminhoSessao, 0777, true);
            }
            session_save_path($caminhoSessao);

            session_start();
        }
    }

    //CRIA O LOGIN DO USUÁRIO
    public static function login($obUser): bool {
        self::init();

        if (!isset($obUser->id, $obUser->email)) {
            return false;
        }

        //DEFINE A SESSÃO DO USUÁRIO
        $_SESSION['usuario-mvc-1'] = [
            'id'    => $obUser->id,
            'id_admin' => $obUser->id_admin,
            'nome'  => $obUser->nome,
            'email' => $obUser->email,
            'nivel' => $obUser->nivel,
            'termos_uso' => $obUser->termos_uso,
            'acesso' => json_decode($obUser->acesso, true) ?? []
        ];

        //SUCESSO
        return true;
    }

    /**
     * Atualiza dados da sessão com o banco (permissões, nível, status).
     * Executa no máximo uma vez por requisição.
     */
    public static function syncSessionFromDatabase(): bool {
        self::init();

        if (self::$sessionSynced) {
            return isset($_SESSION['usuario-mvc-1']);
        }
        self::$sessionSynced = true;

        if (!isset($_SESSION['usuario-mvc-1']['id'])) {
            return false;
        }

        $obUser = EntityUser::getUser(
            'id = '.(int)$_SESSION['usuario-mvc-1']['id'],
            null,
            1,
            'id, nome, email, nivel, id_admin, termos_uso, acesso, ativo'
        )->fetchObject(EntityUser::class);

        if (!$obUser) {
            unset($_SESSION['usuario-mvc-1']);
            return false;
        }

        if ($obUser->ativo !== 's') {
            unset($_SESSION['usuario-mvc-1']);
            return false;
        }

        if ($obUser->nivel === 'Cliente' || $obUser->nivel === 'Empresa') {
            unset($_SESSION['usuario-mvc-1']);
            return false;
        }

        $isMaster = MasterGateHelper::isMasterEmail($obUser->email ?? '');

        // Escola inativa: mantém sessão, mas marca acesso restrito à Assinatura
        $escola = ClientesAssinantes::getEscolaById((int)$obUser->id_admin);
        $bloqueada = ($escola instanceof ClientesAssinantes && !$escola->isAtiva() && !$isMaster && !self::isImpersonating());

        $_SESSION['usuario-mvc-1']['nome']       = $obUser->nome;
        $_SESSION['usuario-mvc-1']['email']      = $obUser->email;
        $_SESSION['usuario-mvc-1']['nivel']      = $obUser->nivel;
        $_SESSION['usuario-mvc-1']['id_admin']   = $obUser->id_admin;
        $_SESSION['usuario-mvc-1']['termos_uso'] = (int)$obUser->termos_uso;
        $_SESSION['usuario-mvc-1']['acesso']     = json_decode($obUser->acesso, true) ?? [];
        $_SESSION['usuario-mvc-1']['assinatura_bloqueada'] = $bloqueada;

        // Em impersonate, mantém flag e não promove a master pelo e-mail do diretor
        if (self::isImpersonating()) {
            $_SESSION['usuario-mvc-1']['is_master'] = false;
            $_SESSION['usuario-mvc-1']['impersonate'] = true;
            $_SESSION['usuario-mvc-1']['termos_uso'] = 1;
            $_SESSION['usuario-mvc-1']['assinatura_bloqueada'] = false;
        } else {
            $_SESSION['usuario-mvc-1']['is_master'] = $isMaster;
            unset($_SESSION['usuario-mvc-1']['impersonate']);
        }

        return true;
    }

    /** Escola suspensa: só Assinatura (e logout). */
    public static function isAssinaturaBloqueada(): bool {
        self::init();
        return !empty($_SESSION['usuario-mvc-1']['assinatura_bloqueada']);
    }

    public static function uriPermitidaQuandoBloqueada(string $uri): bool {
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = $uri;
        }
        $path = strtolower(rtrim($path, '/'));
        if ($path === '' || substr($path, -7) === '/logout' || $path === '/logout') {
            return true;
        }
        // /painel/assinatura e subpaths (POST na mesma rota)
        if (preg_match('#/(painel/assinatura)$#', $path) || preg_match('#/painel/assinatura/#', $path.'/')) {
            return true;
        }
        return false;
    }

    // VERIFICA SE O USUÁRIO ESTÁ LOGADO E SE É UM ADMIN
    public static function isUserLogged() {
        // INICIA A SESSÃO
        self::init();

        // RETORNA A VERIFICAÇÃO
        return isset($_SESSION['usuario-mvc-1']);
    }

    public static function getUserLogedData(): ?array {
    self::init();

    if (!self::isUserLogged()) {
        return null;
    }

    if (!self::syncSessionFromDatabase()) {
        return null;
    }

    if (!array_key_exists('id_admin', $_SESSION['usuario-mvc-1'] ?? [])) {
        return null;
    }

    $idAdmin = (int)($_SESSION['usuario-mvc-1']['id_admin'] ?? 0);
    $dadosEscola = $idAdmin > 0
        ? ClientesAssinantes::getEscolaById($idAdmin)
        : null;

    $usuario = $_SESSION['usuario-mvc-1'];
    // Master sem escola (id_admin 0/null): não exige módulos de tenant
    if (!empty($usuario['is_master']) && $idAdmin <= 0) {
        $usuario['acesso'] = ModuleGateHelper::normalizarAcessoUsuario($usuario['acesso'] ?? []);
    } else {
        $usuario['acesso'] = ModuleGateHelper::getModulosEfetivos(
            $idAdmin,
            $usuario['acesso'] ?? []
        );
    }

    return [
        'usuario' => $usuario,
        'escola'  => $dadosEscola ? (array) $dadosEscola : null
    ];
}


    /**
     * Master entra como Diretor da escola (suporte).
     * Guarda snapshot da sessão master para restaurar depois.
     */
    public static function iniciarImpersonate(EntityUser $diretor, ClientesAssinantes $escola): bool {
        self::init();

        if (!isset($_SESSION['usuario-mvc-1']['id'])) {
            return false;
        }
        if (empty($_SESSION['usuario-mvc-1']['is_master']) && !MasterGateHelper::isMasterEmail($_SESSION['usuario-mvc-1']['email'] ?? '')) {
            return false;
        }

        $_SESSION['master_impersonate'] = [
            'master_snapshot' => $_SESSION['usuario-mvc-1'],
            'escola_id'       => (int)$escola->id,
            'escola_nome'     => (string)$escola->nome,
            'started_at'      => date('Y-m-d H:i:s'),
        ];

        self::$sessionSynced = false;
        self::login($diretor);
        $_SESSION['usuario-mvc-1']['is_master'] = false;
        $_SESSION['usuario-mvc-1']['impersonate'] = true;
        $_SESSION['usuario-mvc-1']['termos_uso'] = 1;

        return true;
    }

    public static function encerrarImpersonate(): bool {
        self::init();

        if (empty($_SESSION['master_impersonate']['master_snapshot'])) {
            return false;
        }

        $snapshot = $_SESSION['master_impersonate']['master_snapshot'];
        unset($_SESSION['master_impersonate']);
        self::$sessionSynced = false;
        $_SESSION['usuario-mvc-1'] = $snapshot;
        $_SESSION['usuario-mvc-1']['is_master'] = true;
        unset($_SESSION['usuario-mvc-1']['impersonate']);

        return true;
    }

    public static function isImpersonating(): bool {
        self::init();
        return !empty($_SESSION['master_impersonate']['master_snapshot']);
    }

    public static function getImpersonateInfo(): ?array {
        self::init();
        if (!self::isImpersonating()) {
            return null;
        }
        return [
            'escola_id'   => (int)($_SESSION['master_impersonate']['escola_id'] ?? 0),
            'escola_nome' => (string)($_SESSION['master_impersonate']['escola_nome'] ?? ''),
        ];
    }

    //EXECUTA O LOGOUT
    public static function logout() {
        //INICIA A SESSÃO
        self::init();

        //DESLOGA O USUÁRIO
        unset($_SESSION['usuario-mvc-1']);
        unset($_SESSION['master_impersonate']);

        //SUCESSO
        return true;
    }

    /** Atualiza sessão após aceite do termo (versão vigente). */
    public static function marcarTermosAceitos(string $versao = ''): void {
        self::init();
        if (!isset($_SESSION['usuario-mvc-1'])) {
            return;
        }
        $_SESSION['usuario-mvc-1']['termos_uso'] = 1;
        if ($versao !== '') {
            $_SESSION['usuario-mvc-1']['termos_versao'] = $versao;
        }
    }

}
