<?php

namespace App\Common\Helpers;

use App\Model\Entity\LmsCurso;
use App\Model\Entity\LmsVitrineAssinatura;

/**
 * Visibilidade do menu/tela Vitrine de cursos.
 */
class LmsVitrineHelper {

	/**
	 * Há algo útil para a escola: catálogo de outras escolas ou licenças próprias.
	 */
	public static function deveExibirParaEscola(int $idAdmin): bool {
		if ($idAdmin <= 0) {
			return false;
		}
		if (!LmsVitrineAssinatura::tabelaExiste() || !LmsCurso::temColunaVitrine()) {
			return false;
		}
		if (!empty(LmsVitrineAssinatura::listAtivasEscola($idAdmin))) {
			return true;
		}
		$row = LmsCurso::get(
			'vitrine_ativo = 1 AND publicado = 1 AND id_admin <> '.(int)$idAdmin,
			null,
			'1',
			'id'
		)->fetch(\PDO::FETCH_ASSOC);
		return !empty($row);
	}
}
