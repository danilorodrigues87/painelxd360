<?php

namespace App\Controller\Api\Editor;

use App\Common\Environment;
use App\Common\Helpers\EditorAuthHelper;
use App\Model\Entity\User;
use App\Model\Entity\LmsEditorToken;
use App\Model\Entity\ClientesAssinantes;
use Firebase\JWT\JWT;

class Auth {

	private static function ok($data, int $code = 200): array {
		return [
			'code' => $code,
			'json' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
		];
	}

	private static function err(string $msg, int $code = 400): array {
		return self::ok(['message' => $msg, 'erro' => $msg], $code);
	}

	private static function jwtKey(): string {
		$key = (string)(Environment::get('JWT_KEY') ?: getenv('JWT_KEY') ?: '');
		return $key !== '' ? $key : 'change-me';
	}

	/**
	 * Troca one-time token do painel por JWT do editor (8h).
	 * Body: { "token": "..." }
	 */
	public static function exchange($request) {
		try {
			$post = $request->getPostVars();
			if (!is_array($post)) {
				$post = [];
			}
			if (empty($post['token'])) {
				$raw = file_get_contents('php://input');
				$decoded = is_string($raw) ? json_decode($raw, true) : null;
				if (is_array($decoded)) {
					$post = $decoded;
				}
			}
			$query = $request->getQueryParams() ?: [];
			$plain = trim((string)($post['token'] ?? $query['token'] ?? ''));
			if ($plain === '') {
				return self::err('Informe o token.', 400);
			}

			try {
				$row = LmsEditorToken::consumir($plain);
			} catch (\Throwable $e) {
				error_log('[editor.auth.exchange] token: '.$e->getMessage());
				return self::err(
					'Falha ao validar token do editor. Confira se o SQL lms_aulas_interativas.sql foi executado. Detalhe: '.$e->getMessage(),
					500
				);
			}

			if (!$row) {
				return self::err('Token inválido ou expirado. Clique novamente em Abrir editor no painel.', 401);
			}

			$user = User::getUserById((int)$row->id_usuario);
			if (!$user instanceof User) {
				return self::err('Usuário inválido.', 401);
			}
			if ((string)($user->nivel ?? '') === 'Cliente') {
				return self::err('Acesso apenas para editores.', 403);
			}

			$idAdminToken = (int)$row->id_admin;
			if (!EditorAuthHelper::usuarioCompativelComToken($user, $idAdminToken)) {
				return self::err('Token inválido (tenant).', 401);
			}

			$idAdminEditor = $idAdminToken;
			$escola = ClientesAssinantes::getEscolaById($idAdminEditor);
			if (!$escola || !$escola->isAtiva()) {
				return self::err('Escola inativa ou não encontrada.', 403);
			}

			$expiresIn = 8 * 3600;
			$payload = [
				'sub' => (int)$user->id,
				'email' => (string)$user->email,
				'id_admin' => $idAdminEditor,
				'role' => 'editor',
				'nivel' => (string)$user->nivel,
				'iat' => time(),
				'exp' => time() + $expiresIn,
			];
			if (EditorAuthHelper::isMasterCatalogEditor($user, $idAdminEditor)) {
				$payload['master_catalog'] = true;
			}
			if (!empty($row->id_curso)) {
				$payload['id_curso'] = (int)$row->id_curso;
			}

			$token = JWT::encode($payload, self::jwtKey(), 'HS256');

			return self::ok([
				'user' => [
					'id' => (string)$user->id,
					'name' => (string)($user->nome ?? ''),
					'email' => (string)($user->email ?? ''),
					'role' => 'editor',
					'idAdmin' => $idAdminEditor,
					'idCurso' => !empty($row->id_curso) ? (int)$row->id_curso : null,
				],
				'tokens' => [
					'accessToken' => $token,
					'refreshToken' => $token,
					'expiresIn' => $expiresIn,
				],
			]);
		} catch (\Throwable $e) {
			error_log('[editor.auth.exchange] '.$e->getMessage()."\n".$e->getTraceAsString());
			return self::err('Erro no exchange: '.$e->getMessage(), 500);
		}
	}
}
