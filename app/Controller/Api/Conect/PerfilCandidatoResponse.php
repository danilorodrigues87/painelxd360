<?php

namespace App\Controller\Api\Conect;

use App\Common\Helpers\ConectApiMapper;
use App\Common\Helpers\ConectCandidatoFormacaoHelper;
use App\Model\Entity\CjCandidato;
use App\Model\Entity\CjCandidatoFormacao;
use App\Model\Entity\CjCandidatoHabilidade;
use App\Model\Entity\User;

trait PerfilCandidatoResponse {

	/** @param array<string,mixed>|CjCandidato $candidato */
	private static function perfilResponse(User $user, $candidato): array {
		$id = is_array($candidato) ? (int)($candidato['id'] ?? 0) : (int)$candidato->id;
		$tipo = is_array($candidato) ? (string)($candidato['tipo'] ?? 'externo') : (string)($candidato->tipo ?? 'externo');

		if (($user->nivel ?? '') === 'Cliente' || $tipo === 'aluno') {
			ConectCandidatoFormacaoHelper::syncAllForUsuario((int)$user->id);
		}

		$row = CjCandidato::getByIdEnriched($id);
		if (!$row) {
			$row = is_array($candidato) ? $candidato : (array)$candidato;
		}

		if (($row['foto'] ?? null) === null && User::temColunaFoto() && !empty($user->foto)) {
			CjCandidato::atualizar($id, ['foto' => (string)$user->foto]);
			$row = CjCandidato::getByIdEnriched($id) ?? $row;
		}

		$habilidades = CjCandidatoHabilidade::listarPorCandidato($id);
		$formacao = ConectCandidatoFormacaoHelper::listarParaApi($id);
		$temSelo = CjCandidatoFormacao::temSeloCertificado($id);

		return [
			'user'      => ConectApiMapper::userCandidato($user, (object)$row),
			'candidato' => ConectApiMapper::candidatoPerfil($row, $habilidades, $formacao, $temSelo, true),
		];
	}
}
