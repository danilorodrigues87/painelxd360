<?php

namespace App\Controller\Api\ConectEmpresa;

use App\Common\Helpers\BrandingHelper;
use App\Common\Helpers\ConectApiMapper;
use App\Model\Entity\CjEmpresa;

class Logo {

	private static function respond(array $body, int $code = 200): array {
		return [
			'code' => $code,
			'json' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
		];
	}

	public static function upload($request): array {
		$empresa = $request->empresa ?? null;
		if (!$empresa instanceof CjEmpresa) {
			return self::respond(['message' => 'Não autenticado.'], 401);
		}
		if (($empresa->status ?? '') !== 'aprovada') {
			return self::respond(['message' => 'Empresa aguardando aprovação.'], 403);
		}

		$files = $request->getFileVars();
		$post = $request->getPostVars() ?: [];
		$restaurar = !empty($post['restaurar']);

		$logo = $restaurar ? null : BrandingHelper::processarUploadConectEmpresaLogo(
			$files['logo'] ?? null,
			$empresa->logo ?? null
		);

		CjEmpresa::atualizar((int)$empresa->id, ['logo' => $logo]);
		$atual = CjEmpresa::getById((int)$empresa->id);

		return self::respond([
			'message' => $restaurar ? 'Logo removida.' : 'Logo atualizada.',
			'empresa' => ConectApiMapper::empresaPerfil($atual),
		]);
	}
}
