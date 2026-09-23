<?php

namespace App\Common\Helpers;

use App\Model\Entity\CrmFunis;
use App\Model\Entity\CrmLeads as EntityCrmLeads;

/**
 * Cria lead CRM para candidatos externos do Conecta Jovem.
 */
class ConectJovemCrmHelper {

	public static function criarLeadExterno(
		int $idAdmin,
		string $nome,
		string $whatsapp,
		?string $email = null,
		?string $cursoInteresse = null,
		?int $cidadeId = null,
		?string $bairro = null,
		?string $nascimento = null,
		?string $responsavelNome = null
	): ?int {
		if ($idAdmin <= 0 || trim($nome) === '') {
			return null;
		}

		$funilId = self::funilConectaJovem($idAdmin);
		$idade = ConectIdadeHelper::calcularIdade($nascimento);
		$dadosLead = [
			'nome' => $nome,
			'whatsapp' => $whatsapp,
			'email' => $email,
			'curso_interesse' => $cursoInteresse ?: 'Empregabilidade',
			'origem' => 'Conecta Jovem',
			'funil_id' => $funilId,
			'bairro' => $bairro,
			'cidade' => $cidadeId !== null && $cidadeId > 0 ? (string)$cidadeId : null,
			'historico_obs' => 'Lead via Conecta Jovem (cadastro externo).',
		];
		if ($idade !== null && $idade > 0) {
			$dadosLead['idade'] = $idade;
		}
		if ($responsavelNome !== null && trim($responsavelNome) !== '') {
			$dadosLead['responsavel_nome'] = trim($responsavelNome);
		}
		$res = CrmPessoaHelper::criarOuAtualizarLead($idAdmin, $dadosLead, 0);

		$lead = $res['lead'] ?? null;
		return ($lead instanceof EntityCrmLeads && (int)$lead->id > 0) ? (int)$lead->id : null;
	}

	private static function funilConectaJovem(int $idAdmin): ?int {
		try {
			foreach (['Conecta Jovem', 'Conect Jovem'] as $nomeFunil) {
				$row = CrmFunis::getFunis(
					'id_admin = '.(int)$idAdmin.' AND nome = "'.$nomeFunil.'"',
					null,
					'1'
				)->fetch(\PDO::FETCH_ASSOC);
				if (!empty($row['id'])) {
					return (int)$row['id'];
				}
			}
			$funil = new CrmFunis();
			$funil->id_admin = $idAdmin;
			$funil->nome = 'Conecta Jovem';
			$funil->ativo = 1;
			$funil->cadastrar();
			return (int)$funil->id ?: null;
		} catch (\Throwable $e) {
			return null;
		}
	}
}
