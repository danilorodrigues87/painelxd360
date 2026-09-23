<?php

namespace App\Controller\Api\Conect;

use App\Common\Helpers\UserFotoHelper;
use App\Model\Entity\CjCandidato;
use App\Model\Entity\User;
use App\Http\Response;

class Foto {

	use PerfilCandidatoResponse;

	private static function respond(array $body, int $code = 200): array {
		return [
			'code' => $code,
			'json' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
		];
	}

	public static function upload($request): array {
		$user = $request->user ?? null;
		$candidato = $request->candidato ?? null;
		if (!$user instanceof User || !$candidato instanceof CjCandidato) {
			return self::respond(['message' => 'Não autenticado.'], 401);
		}

		$files = $request->getFileVars();
		$post = $request->getPostVars() ?: [];
		$restaurar = !empty($post['restaurar']);
		$usarPortal = !empty($post['usarPortal']);

		if ($usarPortal && User::temColunaFoto() && !empty($user->foto)) {
			CjCandidato::atualizar((int)$candidato->id, ['foto' => (string)$user->foto]);
		} elseif ($restaurar) {
			CjCandidato::atualizar((int)$candidato->id, ['foto' => '']);
		} else {
			$nova = UserFotoHelper::processarUpload($files['foto'] ?? null, $candidato->foto ?? null);
			if ($nova !== null) {
				CjCandidato::atualizar((int)$candidato->id, ['foto' => $nova]);
				if (User::temColunaFoto()) {
					$user->foto = $nova;
					$user->atualizar();
				}
			}
		}

		$candidatoAtual = CjCandidato::getByIdEnriched((int)$candidato->id);
		if (!$candidatoAtual) {
			return self::respond(['message' => 'Perfil não encontrado.'], 404);
		}

		$msg = $restaurar ? 'Foto removida.' : 'Foto atualizada.';

		return self::respond(array_merge(
			['message' => $msg],
			self::perfilResponse($user, $candidatoAtual)
		));
	}

	/** Imagem binária da foto do candidato (CORS-safe para PDF/html2canvas). */
	public static function arquivo($request) {
		$user = $request->user ?? null;
		$candidato = $request->candidato ?? null;
		if (!$user instanceof User || !$candidato instanceof CjCandidato) {
			return new Response(401, json_encode(['message' => 'Não autenticado.']), 'application/json');
		}

		$foto = trim((string)($candidato->foto ?? ''));
		if ($foto === '') {
			return new Response(404, json_encode(['message' => 'Sem foto.']), 'application/json');
		}

		$path = UserFotoHelper::caminhoAbsoluto($foto);
		if ($path === null) {
			return new Response(404, json_encode(['message' => 'Arquivo não encontrado.']), 'application/json');
		}

		$mime = mime_content_type($path) ?: 'image/jpeg';
		$body = file_get_contents($path);
		if ($body === false) {
			return new Response(500, json_encode(['message' => 'Falha ao ler foto.']), 'application/json');
		}

		$resp = new Response(200, $body, $mime);
		$resp->addHeader('Cache-Control', 'private, max-age=300');
		$resp->addHeader('X-Content-Type-Options', 'nosniff');
		return $resp;
	}
}
