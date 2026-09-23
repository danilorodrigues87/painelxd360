<?php

namespace App\Controller\Api\Conect;

use App\Common\Helpers\ConectApiMapper;
use App\Common\Helpers\ConectEnderecoHelper;
use App\Common\Helpers\ConectIdadeHelper;
use App\Common\Helpers\ConectRedesSociaisHelper;
use App\Common\Helpers\ConectSchemaHelper;
use App\Common\Helpers\ConectSenhaHelper;
use App\Model\Db\Database;
use App\Model\Entity\CjCandidato;
use App\Model\Entity\CjCandidatoHabilidade;
use App\Model\Entity\User;

class Perfil {

	use PerfilCandidatoResponse;

	private static function respond(array $body, int $code = 200): array {
		return [
			'code' => $code,
			'json' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
		];
	}

	public static function atualizar($request): array {
		$user = $request->user ?? null;
		$candidato = $request->candidato ?? null;
		if (!$user instanceof User || !$candidato instanceof CjCandidato) {
			return self::respond(['message' => 'Não autenticado.'], 401);
		}
		if (!CjCandidato::tabelaExiste()) {
			return self::respond(['message' => 'Módulo não instalado.'], 503);
		}

		$post = $request->getPostVars() ?: [];
		$dados = [];

		if (array_key_exists('nome', $post)) {
			$nome = trim((string)$post['nome']);
			if ($nome === '') {
				return self::respond(['message' => 'Nome é obrigatório.'], 400);
			}
			$dados['nome'] = $nome;
			if ((string)($user->nome ?? '') !== $nome) {
				$user->nome = $nome;
				// Atualiza só o nome — User::atualizar() exige colunas que fetchObject não preenche (nivel, acesso…)
				(new Database('usuarios'))->update('id = '.(int)$user->id, ['nome' => $nome]);
			}
		}

		if (array_key_exists('whatsapp', $post)) {
			$dados['whatsapp'] = preg_replace('/\D+/', '', (string)$post['whatsapp']);
		}
		if (array_key_exists('resumo', $post)) {
			$dados['resumo'] = trim((string)$post['resumo']);
		}
		if (array_key_exists('logradouro', $post)) {
			$val = trim((string)$post['logradouro']);
			$dados['logradouro'] = $val !== '' ? mb_substr($val, 0, 191) : null;
		}
		if (array_key_exists('numero', $post)) {
			$val = trim((string)$post['numero']);
			$dados['numero'] = $val !== '' ? mb_substr($val, 0, 20) : null;
		}
		if (array_key_exists('cidadeId', $post) || array_key_exists('cidade_id', $post)) {
			$cidadeId = (int)($post['cidadeId'] ?? $post['cidade_id'] ?? 0);
			$dados['cidade_id'] = $cidadeId > 0 ? $cidadeId : null;
			if ($cidadeId > 0) {
				$loc = ConectEnderecoHelper::localPorCidadeId($cidadeId);
				if ($loc['uf'] !== '') {
					$dados['uf'] = $loc['uf'];
				}
			}
		}
		if (array_key_exists('bairro', $post)) {
			$bairro = trim((string)$post['bairro']);
			$dados['bairro'] = $bairro !== '' ? mb_substr($bairro, 0, 120) : null;
		}
		if (array_key_exists('uf', $post) && !isset($dados['uf'])) {
			$uf = strtoupper(substr(trim((string)$post['uf']), 0, 2));
			$dados['uf'] = $uf !== '' ? $uf : null;
		}
		if (array_key_exists('disponibilidade', $post)) {
			$disp = trim((string)$post['disponibilidade']);
			if (!in_array($disp, ['imediata', '15_dias', '30_dias', 'a_combinar'], true)) {
				$disp = 'imediata';
			}
			$dados['disponibilidade'] = $disp;
		}
		if (array_key_exists('experiencias', $post)) {
			$dados['experiencias_json'] = ConectEnderecoHelper::encodeListaJson(
				ConectEnderecoHelper::sanitizarExperiencias($post['experiencias'])
			);
		}
		if (array_key_exists('formacaoAcademica', $post)) {
			$dados['formacao_academica_json'] = ConectEnderecoHelper::encodeListaJson(
				ConectEnderecoHelper::sanitizarFormacaoAcademica($post['formacaoAcademica'])
			);
		}
		if (array_key_exists('redesSociais', $post) && ConectSchemaHelper::temColuna('cj_candidatos', 'redes_sociais_json')) {
			$dados['redes_sociais_json'] = ConectRedesSociaisHelper::encode(
				ConectRedesSociaisHelper::sanitizar($post['redesSociais'])
			);
		}

		if (array_key_exists('nascimento', $post)
			|| array_key_exists('dataNascimento', $post)
			|| array_key_exists('responsavelNome', $post)
			|| array_key_exists('responsavelConsentimento', $post)) {
			$nascVal = ConectIdadeHelper::extrairValidarNascimento($post, true);
			if (!$nascVal['ok']) {
				return self::respond(['message' => $nascVal['erro'] ?? 'Data de nascimento inválida.'], 400);
			}
			if (!empty($nascVal['dados'])) {
				$dados = array_merge($dados, $nascVal['dados']);
			}
		}

		if ($dados !== []) {
			try {
				CjCandidato::atualizar((int)$candidato->id, $dados);
			} catch (\Throwable $e) {
				return self::respond(['message' => 'Erro ao salvar perfil. Verifique se o SQL de ajustes foi aplicado no banco.'], 500);
			}
		}

		if (array_key_exists('habilidades', $post) && is_array($post['habilidades'])) {
			CjCandidatoHabilidade::sincronizar((int)$candidato->id, $post['habilidades']);
		}

		$faltando = ConectSchemaHelper::faltando('cj_candidatos', [
			'logradouro', 'numero', 'formacao_academica_json', 'experiencias_json',
		]);

		$candidatoAtual = CjCandidato::getByIdEnriched((int)$candidato->id);
		if (!$candidatoAtual) {
			return self::respond(['message' => 'Perfil não encontrado.'], 404);
		}

		$body = array_merge(
			['message' => 'Perfil atualizado.'],
			self::perfilResponse($user, $candidatoAtual)
		);
		if ($faltando !== []) {
			$body['message'] = 'Perfil parcialmente atualizado. Endereço e currículo exigem atualização do banco de dados.';
			$body['sqlAviso'] = 'Execute database/conect_jovem_ajustes.sql no phpMyAdmin (colunas faltando: '.implode(', ', $faltando).').';
		}

		return self::respond($body);
	}

	public static function alterarSenha($request): array {
		$user = $request->user ?? null;
		if (!$user instanceof User) {
			return self::respond(['message' => 'Não autenticado.'], 401);
		}

		$post = $request->getPostVars() ?: [];
		$result = ConectSenhaHelper::alterar($user, $post, 6);
		if (!$result['ok']) {
			return self::respond(['message' => $result['message']], $result['code'] ?? 400);
		}

		return self::respond(['message' => $result['message']]);
	}
}
