<?php

namespace App\Controller\Api\Student;

use App\Model\Entity\LmsCertificado;
use App\Controller\Admin\CertificadoEadPdf;
use App\Common\Helpers\LmsCertificadoHelper;

class Certificates {

	private static function ok($data, int $code = 200): array {
		return ['code' => $code, 'json' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
	}

	public static function list($request) {
		$u = $request->user;
		return self::ok(LmsCertificadoHelper::listForApi((int)$u->id_admin, (int)$u->id));
	}

	/**
	 * HTML + jsPDF (sem QR). Só se o curso ainda estiver 100%.
	 */
	public static function html($request, $id) {
		$u = $request->user;
		$id = (int)$id;
		$c = null;
		if (LmsCertificado::tabelasExistem()) {
			$c = LmsCertificado::getById($id);
		}
		if (
			!$c instanceof LmsCertificado
			|| (int)$c->id_aluno !== (int)$u->id
			|| (int)$c->id_admin !== (int)$u->id_admin
		) {
			return [
				'code' => 404,
				'json' => '<!DOCTYPE html><html><body><p>Certificado não encontrado.</p></body></html>',
				'contentType' => 'text/html; charset=utf-8',
			];
		}

		if (!LmsCertificadoHelper::aindaValido($c, (int)$u->id, (int)$u->id_admin)) {
			return [
				'code' => 403,
				'json' => '<!DOCTYPE html><html><body><p>Certificado desatualizado. Conclua o conteúdo novo do curso para emitir a versão atualizada.</p></body></html>',
				'contentType' => 'text/html; charset=utf-8',
			];
		}

		// Refresh snapshot antes de gerar PDF
		$curso = \App\Model\Entity\LmsCurso::getByIdAdmin((int)$c->id_curso, (int)$u->id_admin);
		if ($curso) {
			$fresh = LmsCertificadoHelper::emitirSeCursoCompleto((int)$u->id, (int)$u->id_admin, $curso);
			if ($fresh instanceof LmsCertificado) {
				$c = $fresh;
			}
		}

		try {
			$html = CertificadoEadPdf::geraHtml($c);
		} catch (\Throwable $e) {
			$html = '<!DOCTYPE html><html><body><p>Não foi possível gerar o certificado. Tente novamente.</p></body></html>';
		}
		return [
			'code' => 200,
			'json' => $html,
			'contentType' => 'text/html; charset=utf-8',
		];
	}
}
