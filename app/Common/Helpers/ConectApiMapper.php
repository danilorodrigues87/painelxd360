<?php

namespace App\Common\Helpers;

class ConectApiMapper {

	public static function tokens(string $accessToken, int $expiresIn = 86400): array {
		return [
			'accessToken'  => $accessToken,
			'refreshToken' => $accessToken,
			'expiresIn'    => $expiresIn,
		];
	}

	public static function userCandidato($user, $candidato): array {
		return [
			'id'          => (int)($user->id ?? 0),
			'nome'        => (string)($user->nome ?? ''),
			'email'       => (string)($user->email ?? ''),
			'nivel'       => 'Candidato',
			'candidatoId' => (int)($candidato->id ?? 0),
			'idAdmin'     => (int)($candidato->id_admin ?? 0),
			'tipo'        => (string)($candidato->tipo ?? 'externo'),
		];
	}

	/**
	 * @param object|array<string,mixed> $candidato
	 * @param list<string> $habilidades
	 * @param list<array<string,mixed>> $formacao
	 */
	public static function candidatoPerfil($candidato, array $habilidades = [], array $formacao = [], bool $temSelo = false, bool $privado = false): array {
		$c = is_array($candidato) ? $candidato : (array)$candidato;
		if (!empty($c['cidade_id']) && empty($c['cidade_nome'])) {
			$loc = ConectEnderecoHelper::localPorCidadeId((int)$c['cidade_id']);
			$c['cidade_nome'] = $loc['cidadeNome'];
			if (empty($c['uf'])) {
				$c['uf'] = $loc['uf'];
			}
			if (empty($c['estado_id']) && !empty($loc['estadoId'])) {
				$c['estado_id'] = $loc['estadoId'];
			}
		}
		$fotoBasename = $c['foto'] ?? null;
		$fotoUrl = null;
		if (!empty($fotoBasename)) {
			$fotoUrl = UserFotoHelper::urlPublica((string)$fotoBasename);
		}
		$formacaoAcad = ConectEnderecoHelper::decodeListaJson($c['formacao_academica_json'] ?? null);
		$experiencias = ConectEnderecoHelper::decodeListaJson($c['experiencias_json'] ?? null);
		$redes = ConectRedesSociaisHelper::decode($c['redes_sociais_json'] ?? null);
		$idadePub = ConectIdadeHelper::payloadPublico($c['nascimento'] ?? null);
		$payload = [
			'id'              => (int)($c['id'] ?? 0),
			'nome'            => (string)($c['nome'] ?? ''),
			'email'           => (string)($c['email'] ?? ''),
			'whatsapp'        => (string)($c['whatsapp'] ?? ''),
			'resumo'          => (string)($c['resumo'] ?? ''),
			'idade'           => $idadePub['idade'],
			'isMenor'         => $idadePub['isMenor'],
			'temNascimento'   => !empty($c['nascimento']),
			'cidadeId'        => isset($c['cidade_id']) && (int)$c['cidade_id'] > 0 ? (int)$c['cidade_id'] : null,
			'cidadeNome'      => (string)($c['cidade_nome'] ?? ''),
			'estadoId'        => isset($c['estado_id']) && (int)$c['estado_id'] > 0 ? (int)$c['estado_id'] : null,
			'logradouro'      => (string)($c['logradouro'] ?? ''),
			'numero'          => (string)($c['numero'] ?? ''),
			'bairro'          => (string)($c['bairro'] ?? ''),
			'uf'              => strtoupper((string)($c['uf'] ?? '')),
			'endereco'        => ConectEnderecoHelper::formatarEndereco($c),
			'disponibilidade' => (string)($c['disponibilidade'] ?? 'imediata'),
			'tipo'            => (string)($c['tipo'] ?? 'externo'),
			'fotoUrl'         => $fotoUrl,
			'habilidades'     => $habilidades,
			'formacao'        => $formacao,
			'formacaoAcademica'=> $formacaoAcad,
			'experiencias'    => $experiencias,
			'temSeloCertificado' => $temSelo,
			'redesSociais'    => $redes,
		];
		if ($privado) {
			$payload['nascimento'] = !empty($c['nascimento']) ? (string)$c['nascimento'] : null;
			$payload['responsavelNome'] = !empty($c['responsavel_nome']) ? (string)$c['responsavel_nome'] : null;
			$payload['responsavelConsentimento'] = !empty($c['responsavel_consentimento_em']);
		}
		return $payload;
	}

	/**
	 * @param array<string,mixed>|object $row
	 */
	public static function formacao($row): array {
		$r = is_array($row) ? $row : (array)$row;
		return [
			'id'              => (int)($r['id'] ?? 0),
			'titulo'          => (string)($r['titulo'] ?? ''),
			'origem'          => (string)($r['origem'] ?? 'manual'),
			'status'          => (string)($r['status'] ?? 'em_andamento'),
			'cargaH'          => isset($r['carga_h']) ? (int)$r['carga_h'] : null,
			'seloCertificado' => !empty($r['selo_certificado']),
			'concluidoEm'     => self::normalizarConcluidoEm((string)($r['concluido_em'] ?? '')),
		];
	}

	private static function normalizarConcluidoEm(string $value): string {
		$value = trim($value);
		if ($value === '' || $value === '0000-00-00' || str_starts_with($value, '0000-00-00')) {
			return '';
		}
		return $value;
	}

	/**
	 * @param array<string,mixed>|object $row
	 */
	public static function candidatura($row): array {
		$r = is_array($row) ? $row : (array)$row;
		return [
			'id'               => (int)($r['id'] ?? 0),
			'vagaId'           => (int)($r['id_vaga'] ?? 0),
			'vagaTitulo'       => (string)($r['vaga_titulo'] ?? ''),
			'vagaSlug'         => (string)($r['vaga_slug'] ?? ''),
			'tipoVaga'         => (string)($r['tipo_vaga'] ?? ''),
			'empresaNome'      => (string)($r['empresa_nome'] ?? ''),
			'status'           => (string)($r['status'] ?? 'enviada'),
			'mensagemCandidato'=> (string)($r['mensagem_candidato'] ?? ''),
			'createdAt'        => (string)($r['created_at'] ?? ''),
		];
	}

	/**
	 * Candidatura enriquecida para a área da empresa.
	 * @param array<string,mixed>|object $row
	 */
	public static function candidaturaEmpresa($row): array {
		$r = is_array($row) ? $row : (array)$row;
		$base = self::candidatura($row);
		$idadePub = ConectIdadeHelper::payloadPublico($r['candidato_nascimento'] ?? null);
		return array_merge($base, [
			'candidatoId'             => (int)($r['id_candidato'] ?? 0),
			'candidatoNome'           => (string)($r['candidato_nome'] ?? ''),
			'candidatoEmail'          => (string)($r['candidato_email'] ?? ''),
			'candidatoWhatsapp'       => (string)($r['candidato_whatsapp'] ?? ''),
			'candidatoResumo'         => (string)($r['candidato_resumo'] ?? ''),
			'candidatoDisponibilidade'=> (string)($r['candidato_disponibilidade'] ?? ''),
			'candidatoTipo'           => (string)($r['candidato_tipo'] ?? ''),
			'candidatoIdade'          => $idadePub['idade'],
			'candidatoIsMenor'        => $idadePub['isMenor'],
			'mensagemEmpresa'         => (string)($r['mensagem_empresa'] ?? ''),
		]);
	}

	public static function empresaPerfil($empresa): array {
		$e = is_array($empresa) ? $empresa : (array)$empresa;
		if (!empty($e['cidade_id']) && empty($e['cidade_nome'])) {
			$loc = ConectEnderecoHelper::localPorCidadeId((int)$e['cidade_id']);
			$e['cidade_nome'] = $loc['cidadeNome'];
			if (empty($e['uf'])) {
				$e['uf'] = $loc['uf'];
			}
			if (!empty($loc['estadoId'])) {
				$e['estado_id'] = $loc['estadoId'];
			}
		}
		$redes = ConectRedesSociaisHelper::decode($e['redes_sociais_json'] ?? null);
		return [
			'id'           => (int)($e['id'] ?? 0),
			'cnpj'         => (string)($e['cnpj'] ?? ''),
			'razaoSocial'  => (string)($e['razao_social'] ?? ''),
			'nomeFantasia' => (string)($e['nome_fantasia'] ?? ''),
			'logoUrl'      => BrandingHelper::urlConectEmpresaLogo($e['logo'] ?? null),
			'whatsapp'     => (string)($e['whatsapp'] ?? ''),
			'email'        => (string)($e['email'] ?? ''),
			'contatoNome'  => (string)($e['contato_nome'] ?? ''),
			'cidadeId'     => isset($e['cidade_id']) && (int)$e['cidade_id'] > 0 ? (int)$e['cidade_id'] : null,
			'cidadeNome'   => (string)($e['cidade_nome'] ?? ''),
			'estadoId'     => isset($e['estado_id']) && (int)$e['estado_id'] > 0 ? (int)$e['estado_id'] : null,
			'logradouro'   => (string)($e['logradouro'] ?? ''),
			'numero'       => (string)($e['numero'] ?? ''),
			'bairro'       => (string)($e['bairro'] ?? ''),
			'uf'           => strtoupper((string)($e['uf'] ?? '')),
			'endereco'     => ConectEnderecoHelper::formatarEndereco($e),
			'status'       => (string)($e['status'] ?? 'pendente'),
			'redesSociais' => $redes,
		];
	}

	/**
	 * @param array<string,mixed>|object $row
	 */
	public static function notificacao($row): array {
		$r = is_array($row) ? $row : (array)$row;
		return [
			'id'        => (int)($r['id'] ?? 0),
			'tipo'      => (string)($r['tipo'] ?? 'system'),
			'titulo'    => (string)($r['titulo'] ?? ''),
			'mensagem'  => (string)($r['mensagem'] ?? ''),
			'link'      => !empty($r['link']) ? (string)$r['link'] : null,
			'lida'      => !empty($r['lido_em']),
			'createdAt' => (string)($r['created_at'] ?? ''),
		];
	}

	public static function userEmpresa($user, $empresa): array {
		$e = is_array($empresa) ? $empresa : (array)$empresa;
		return [
			'id'        => (int)($user->id ?? 0),
			'nome'      => (string)($user->nome ?? ''),
			'email'     => (string)($user->email ?? ''),
			'nivel'     => 'Empresa',
			'empresaId' => (int)($e['id'] ?? 0),
			'status'    => (string)($e['status'] ?? 'pendente'),
		];
	}

	/**
	 * @param array<string,mixed>|object $row
	 */
	public static function vaga($row): array {
		$r = is_array($row) ? $row : (array)$row;
		return [
			'id'          => (int)($r['id'] ?? 0),
			'slug'        => (string)($r['slug'] ?? ''),
			'titulo'      => (string)($r['titulo'] ?? ''),
			'tipoVaga'    => (string)($r['tipo_vaga'] ?? ''),
			'descricao'   => (string)($r['descricao'] ?? ''),
			'requisitos'  => (string)($r['requisitos'] ?? ''),
			'cidadeId'    => isset($r['cidade_id']) ? (int)$r['cidade_id'] : null,
			'cidadeNome'  => (string)($r['cidade_nome'] ?? ''),
			'bairro'      => (string)($r['bairro'] ?? ''),
			'uf'          => (string)($r['uf'] ?? ''),
			'modalidade'  => (string)($r['modalidade'] ?? ''),
			'empresaId'   => (int)($r['id_empresa'] ?? 0),
			'empresaNome' => (string)($r['empresa_nome'] ?? ''),
			'empresaLogoUrl' => BrandingHelper::urlConectEmpresaLogo($r['empresa_logo'] ?? null),
			'status'      => (string)($r['status'] ?? ''),
			'publicadaEm' => (string)($r['publicada_em'] ?? ''),
			'viewsCount'  => (int)($r['views_count'] ?? 0),
		];
	}

	public static function empresa($row): array {
		$r = is_array($row) ? $row : (array)$row;
		return [
			'id'           => (int)($r['id'] ?? 0),
			'nomeFantasia' => (string)($r['nome_fantasia'] ?? $r['razao_social'] ?? ''),
			'razaoSocial'  => (string)($r['razao_social'] ?? ''),
			'logoUrl'      => BrandingHelper::urlConectEmpresaLogo($r['logo'] ?? null),
			'cidadeId'     => isset($r['cidade_id']) ? (int)$r['cidade_id'] : null,
			'cidadeNome'   => (string)($r['cidade_nome'] ?? ''),
			'uf'           => (string)($r['uf'] ?? ''),
			'redesSociais' => ConectRedesSociaisHelper::decode($r['redes_sociais_json'] ?? null),
		];
	}

	public static function branding($row): array {
		$r = is_array($row) ? $row : (array)$row;
		$cores = [];
		if (!empty($r['cores_json'])) {
			$decoded = json_decode((string)$r['cores_json'], true);
			if (is_array($decoded)) {
				$cores = $decoded;
			}
		}
		return [
			'nomePortal'         => (string)($r['nome_portal'] ?? 'Conecta Jovem'),
			'logoUrl'            => BrandingHelper::urlConectLogo($r['logo'] ?? null),
			'heroImageUrl'       => BrandingHelper::urlConectHero($r['hero_image'] ?? null),
			'textoInstitucional' => (string)($r['texto_institucional'] ?? ''),
			'redesSociais'       => ConectRedesSociaisHelper::decode($r['redes_sociais_json'] ?? null),
			'cores'              => $cores,
		];
	}

	private static function absUrl(string $path): string {
		if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
			return $path;
		}
		return rtrim((string)URL, '/').'/'.ltrim($path, '/');
	}

	public static function slugify(string $text): string {
		$text = mb_strtolower(trim($text));
		$map = [
			'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
			'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
			'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
			'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
			'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
			'ç' => 'c', 'ñ' => 'n',
		];
		$text = strtr($text, $map);
		if (function_exists('iconv')) {
			$ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
			if ($ascii !== false && $ascii !== '') {
				$text = $ascii;
			}
		}
		$text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
		$text = preg_replace('/-+/', '-', $text) ?? '';
		$text = trim($text, '-');
		if ($text === '') {
			$text = 'vaga';
		}
		return mb_substr($text, 0, 200);
	}

	/**
	 * @param array<string,mixed> $row
	 */
	public static function blogCategoria(array $row): array {
		return [
			'id'    => (int)($row['id'] ?? 0),
			'nome'  => (string)($row['nome'] ?? ''),
			'slug'  => (string)($row['slug'] ?? ''),
			'ordem' => (int)($row['ordem'] ?? 0),
		];
	}

	/**
	 * @param array<string,mixed> $row
	 */
	public static function blogPostResumo(array $row): array {
		return [
			'id'             => (int)($row['id'] ?? 0),
			'titulo'         => (string)($row['titulo'] ?? ''),
			'slug'           => ConectApiMapper::slugify((string)($row['slug'] ?? '')),
			'resumo'         => (string)($row['resumo'] ?? ''),
			'capaUrl'        => BrandingHelper::urlConectBlogImagem($row['capa'] ?? null),
			'categoriaNome'  => (string)($row['categoria_nome'] ?? ''),
			'categoriaSlug'  => (string)($row['categoria_slug'] ?? ''),
			'autorNome'      => (string)($row['autor_nome'] ?? 'Conecta Jovem'),
			'publicadoEm'    => (string)($row['publicado_em'] ?? ''),
			'comentariosCount' => (int)($row['comentarios_count'] ?? 0),
		];
	}

	/**
	 * @param array<string,mixed> $row
	 */
	public static function blogPost(array $row): array {
		$resumo = self::blogPostResumo($row);
		$resumo['corpoHtml'] = ConectBlogSanitizer::html((string)($row['corpo_html'] ?? ''));
		$resumo['metaTitle'] = (string)($row['meta_title'] ?? $row['titulo'] ?? '');
		$resumo['metaDescription'] = (string)($row['meta_description'] ?? $row['resumo'] ?? '');
		return $resumo;
	}

	/**
	 * @param array<string,mixed> $row
	 */
	public static function blogComentario(array $row): array {
		$tipo = (string)($row['tipo_autor'] ?? '');
		$avatarUrl = null;
		if ($tipo === 'candidato' && !empty($row['candidato_foto'])) {
			$avatarUrl = UserFotoHelper::urlPublica((string)$row['candidato_foto']);
		} elseif ($tipo === 'empresa' && !empty($row['empresa_logo'])) {
			$avatarUrl = BrandingHelper::urlConectEmpresaLogo($row['empresa_logo'] ?? null);
		}
		return [
			'id'           => (int)($row['id'] ?? 0),
			'texto'        => (string)($row['texto'] ?? ''),
			'nomeExibicao' => (string)($row['nome_exibicao'] ?? ''),
			'tipoAutor'    => $tipo,
			'avatarUrl'    => $avatarUrl,
			'createdAt'    => (string)($row['created_at'] ?? ''),
			'usuarioId'    => (int)($row['id_usuario'] ?? 0),
		];
	}

	/**
	 * @param array<string,mixed> $row
	 */
	public static function depoimento(array $row): array {
		$tipo = (string)($row['tipo_autor'] ?? 'manual');
		$avatarUrl = null;
		if (!empty($row['avatar_arquivo'])) {
			$avatarUrl = BrandingHelper::urlConectDepoimentoAvatar($row['avatar_arquivo']);
		} elseif ($tipo === 'candidato' && !empty($row['candidato_foto'])) {
			$avatarUrl = UserFotoHelper::urlPublica((string)$row['candidato_foto']);
		} elseif ($tipo === 'empresa' && !empty($row['empresa_logo'])) {
			$avatarUrl = BrandingHelper::urlConectEmpresaLogo($row['empresa_logo'] ?? null);
		}
		return [
			'id'           => (int)($row['id'] ?? 0),
			'texto'        => (string)($row['texto'] ?? ''),
			'nome'         => (string)($row['nome_exibicao'] ?? ''),
			'cargo'        => (string)($row['cargo'] ?? ''),
			'tipoAutor'    => $tipo,
			'avatarUrl'    => $avatarUrl,
		];
	}
}
