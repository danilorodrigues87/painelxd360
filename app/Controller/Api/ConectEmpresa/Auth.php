<?php

namespace App\Controller\Api\ConectEmpresa;

use App\Common\Helpers\ConectApiMapper;
use App\Common\Helpers\ConectCandidatoAuthHelper;
use App\Common\Helpers\ConectCnpjHelper;
use App\Common\Helpers\ConectEnderecoHelper;
use App\Common\Helpers\ConectSenhaRecuperacaoHelper;
use App\Model\Entity\CjEmpresa;
use App\Model\Entity\User;
use Firebase\JWT\JWT;

class Auth {

	private static function respond(array $body, int $code = 200): array {
		return [
			'code' => $code,
			'json' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
		];
	}

	public static function login($request): array {
		$post = $request->getPostVars() ?: [];
		$email = trim((string)($post['email'] ?? ''));
		$password = (string)($post['password'] ?? $post['senha'] ?? '');
		if ($email === '' || $password === '') {
			return self::respond(['message' => 'Informe email e senha.'], 400);
		}
		$user = User::getUserByEmail($email);
		if (!$user instanceof User || !password_verify($password, (string)$user->senha)) {
			return self::respond(['message' => 'Credenciais inválidas.'], 401);
		}
		if (($user->nivel ?? '') !== 'Empresa') {
			return self::respond([
				'message' => ConectCandidatoAuthHelper::mensagemLoginIncorreto($user, 'empresa'),
			], 403);
		}
		$empresa = CjEmpresa::getByUsuarioId((int)$user->id);
		if (!$empresa) {
			return self::respond(['message' => 'Perfil de empresa não encontrado.'], 403);
		}

		$expiresIn = 86400;
		$token = JWT::encode([
			'sub'         => (int)$user->id,
			'email'       => $user->email,
			'nivel'       => 'Empresa',
			'id_empresa'  => (int)$empresa->id,
			'iat'         => time(),
			'exp'         => time() + $expiresIn,
		], getenv('JWT_KEY') ?: 'change-me', 'HS256');

		return self::respond([
			'user'   => ConectApiMapper::userEmpresa($user, $empresa),
			'tokens' => ConectApiMapper::tokens($token, $expiresIn),
		]);
	}

	public static function register($request): array {
		if (!CjEmpresa::tabelaExiste()) {
			return self::respond(['message' => 'Módulo Conecta Jovem não instalado (SQL).'], 503);
		}
		$post = $request->getPostVars() ?: [];
		$cnpj = preg_replace('/\D+/', '', (string)($post['cnpj'] ?? ''));
		$razao = trim((string)($post['razaoSocial'] ?? $post['razao_social'] ?? ''));
		$fantasia = trim((string)($post['nomeFantasia'] ?? $post['nome_fantasia'] ?? $razao));
		$email = strtolower(trim((string)($post['email'] ?? '')));
		$senha = (string)($post['password'] ?? $post['senha'] ?? '');
		$whatsapp = preg_replace('/\D+/', '', (string)($post['whatsapp'] ?? ''));
		$contato = trim((string)($post['contatoNome'] ?? $post['contato_nome'] ?? ''));
		$cidadeId = (int)($post['cidadeId'] ?? $post['cidade_id'] ?? 0);
		$uf = strtoupper(substr(trim((string)($post['uf'] ?? '')), 0, 2));
		if ($cidadeId > 0) {
			$loc = ConectEnderecoHelper::localPorCidadeId($cidadeId);
			if ($loc['uf'] !== '') {
				$uf = $loc['uf'];
			}
		}

		$autoAprovada = ConectCnpjHelper::validar($cnpj);
		$statusEmpresa = $autoAprovada ? 'aprovada' : 'pendente';

		if (strlen($cnpj) !== 14 || $razao === '' || $email === '' || strlen($senha) < 6) {
			return self::respond(['message' => 'CNPJ, razão social, e-mail e senha são obrigatórios.'], 400);
		}
		if (!ConectCnpjHelper::validar($cnpj)) {
			return self::respond(['message' => 'CNPJ inválido. Verifique os dígitos informados.'], 400);
		}
		if (CjEmpresa::getByCnpj($cnpj)) {
			return self::respond(['message' => 'CNPJ já cadastrado.'], 409);
		}
		if (User::getUserByEmail($email)) {
			return self::respond(['message' => 'E-mail já cadastrado.'], 409);
		}

		$user = new User();
		$user->nome = $fantasia !== '' ? $fantasia : $razao;
		$user->email = $email;
		$user->senha = password_hash($senha, PASSWORD_DEFAULT);
		$user->nivel = 'Empresa';
		$user->id_admin = 0;
		$user->whatsapp = $whatsapp;
		$user->ativo = 1;
		$user->cadastrar();
		if ((int)$user->id <= 0) {
			return self::respond(['message' => 'Erro ao criar usuário.'], 500);
		}

		$empresaId = CjEmpresa::inserir([
			'id_usuario'    => (int)$user->id,
			'cnpj'          => $cnpj,
			'razao_social'  => $razao,
			'nome_fantasia' => $fantasia,
			'whatsapp'      => $whatsapp,
			'email'         => $email,
			'contato_nome'  => $contato,
			'cidade_id'     => $cidadeId > 0 ? $cidadeId : null,
			'uf'            => $uf !== '' ? $uf : null,
			'status'        => $statusEmpresa,
			'aprovada_em'   => $autoAprovada ? date('Y-m-d H:i:s') : null,
		]);

		if (!$empresaId) {
			return self::respond(['message' => 'Erro ao criar empresa.'], 500);
		}

		$empresa = CjEmpresa::getById($empresaId);
		return self::respond([
			'message' => $autoAprovada
				? 'Cadastro concluído! CNPJ validado — sua empresa já está aprovada para publicar vagas.'
				: 'Cadastro recebido. Aguarde aprovação da equipe CTI.',
			'empresa' => ConectApiMapper::userEmpresa($user, $empresa),
		], 201);
	}

	public static function me($request): array {
		$user = $request->user ?? null;
		$empresa = $request->empresa ?? null;
		if (!$user || !$empresa) {
			return self::respond(['message' => 'Não autenticado.'], 401);
		}
		$empresaRow = CjEmpresa::getByIdEnriched((int)$empresa->id) ?? (array)$empresa;
		return self::respond([
			'user'    => ConectApiMapper::userEmpresa($user, $empresaRow),
			'empresa' => ConectApiMapper::empresaPerfil($empresaRow),
		]);
	}

	public static function forgotPassword($request): array {
		$post = $request->getPostVars() ?: [];
		$email = (string)($post['email'] ?? '');
		$res = ConectSenhaRecuperacaoHelper::solicitar($email, 'empresa');
		return self::respond(['message' => $res['message']], $res['code'] ?? ($res['ok'] ? 200 : 400));
	}

	public static function resetPassword($request): array {
		$post = $request->getPostVars() ?: [];
		$codigo = (string)($post['code'] ?? $post['codigo'] ?? '');
		$nova = (string)($post['newPassword'] ?? $post['senha'] ?? $post['password'] ?? '');
		$confirma = (string)($post['confirmPassword'] ?? $post['senha_confirma'] ?? $nova);
		$res = ConectSenhaRecuperacaoHelper::redefinir($codigo, $nova, $confirma, 'empresa');
		return self::respond(['message' => $res['message']], $res['code'] ?? ($res['ok'] ? 200 : 400));
	}
}
