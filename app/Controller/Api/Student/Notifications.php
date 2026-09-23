<?php

namespace App\Controller\Api\Student;

use App\Common\Helpers\LmsNotificacaoHelper;

class Notifications {

	private static function ok($data, int $code = 200): array {
		return ['code' => $code, 'json' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
	}

	private static function err(string $msg, int $code = 400): array {
		return self::ok(['message' => $msg], $code);
	}

	public static function list($request) {
		$u = $request->user;
		return self::ok(LmsNotificacaoHelper::listForApi((int)$u->id_admin, (int)$u->id, 50, false));
	}

	public static function markAllRead($request) {
		$u = $request->user;
		LmsNotificacaoHelper::marcarTodasLidas((int)$u->id_admin, (int)$u->id);
		return self::ok(['ok' => true]);
	}

	public static function markRead($request, $id) {
		$u = $request->user;
		$ok = LmsNotificacaoHelper::marcarLida((int)$u->id_admin, (int)$u->id, (int)$id);
		if (!$ok) {
			return self::err('Notificação não encontrada.', 404);
		}
		return self::ok(['ok' => true]);
	}
}
