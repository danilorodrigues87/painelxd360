<?php

namespace App\Controller\Api\Student;

use App\Common\Helpers\FinanceiroAlunoHelper;

class Finance {

	private static function ok($data, int $code = 200): array {
		return ['code' => $code, 'json' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
	}

	public static function summary($request) {
		$u = $request->user;
		return self::ok(FinanceiroAlunoHelper::forStudentApi((int)$u->id_admin, (int)$u->id));
	}
}
