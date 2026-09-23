<?php

namespace App\Common\Helpers;

use App\Model\Entity\CjAnuncio;
use App\Model\Entity\CjAnuncioConfig;
use App\Model\Entity\CjAnuncioEvento;
use App\Model\Entity\CjEmpresa;

class ConectAnuncioHelper {

	public const SLOTS = [
		'footer_carousel'  => 'Rodapé (carrossel)',
		'home_mid'         => 'Home (meio da página)',
		'vagas_sidebar'    => 'Listagem de vagas (lateral)',
		'blog_sidebar'     => 'Blog (lateral vertical)',
		'blog_artigo_fim'  => 'Blog (final do artigo)',
	];

	/** Dimensões sugeridas por posição (referência para o anunciante). */
	public const SLOT_DIMENSOES = [
		'footer_carousel' => [
			'sugestao' => '728×90 px ou 970×90 px',
			'hint'     => 'Banner bem achatado (horizontal), ideal para rodapé.',
		],
		'home_mid' => [
			'sugestao' => '728×200 px ou 970×250 px',
			'hint'     => 'Retângulo médio no meio da home.',
		],
		'vagas_sidebar' => [
			'sugestao' => '300×250 px ou 300×600 px',
			'hint'     => 'Formato lateral na listagem de vagas.',
		],
		'blog_sidebar' => [
			'sugestao' => '160×600 px ou 300×600 px',
			'hint'     => 'Banner vertical na lateral do artigo.',
		],
		'blog_artigo_fim' => [
			'sugestao' => '728×90 px ou 970×250 px',
			'hint'     => 'Horizontal ao final do texto do artigo.',
		],
	];

	public static function slotLegado(string $slot): string {
		return $slot === 'blog_inline' ? 'blog_artigo_fim' : $slot;
	}

	public static function dimensaoSlot(string $slot): array {
		$slot = self::slotLegado($slot);
		return self::SLOT_DIMENSOES[$slot] ?? ['sugestao' => '728×90 px', 'hint' => ''];
	}

	public const LINK_TIPOS = ['url', 'instagram', 'whatsapp'];

	public const STATUS_LABELS = [
		'rascunho'  => 'Rascunho',
		'pendente'  => 'Aguardando aprovação',
		'ativo'     => 'Ativo',
		'pausado'   => 'Pausado',
		'rejeitado' => 'Rejeitado',
		'expirado'  => 'Expirado',
	];

	/** @return array<int,array<string,mixed>> */
	public static function listarPublico(string $slot, ?string $uf, ?int $cidadeId, int $limit = 8): array {
		$slot = self::slotLegado($slot);
		if (!CjAnuncio::tabelaExiste() || !isset(self::SLOTS[$slot])) {
			return [];
		}
		$config = CjAnuncioConfig::get();
		$habilitados = $config['slots_habilitados'] ?? [];
		if (is_array($habilitados) && !empty($habilitados) && !in_array($slot, $habilitados, true)) {
			return [];
		}
		$uf = strtoupper(trim((string)$uf));
		if ($uf !== '' && strlen($uf) !== 2) {
			$uf = '';
		}
		$cidadeId = ($cidadeId ?? 0) > 0 ? $cidadeId : null;
		$rows = CjAnuncio::listarAtivosPublico($slot, $uf !== '' ? $uf : null, $cidadeId, $limit);
		$out = [];
		foreach ($rows as $row) {
			$out[] = self::mapPublico($row);
		}
		return $out;
	}

	/** @param array<string,mixed> $row */
	public static function mapPublico(array $row): array {
		$imagem = BrandingHelper::urlConectAnuncioImagem($row['imagem_arquivo'] ?? null);
		$imagemMobile = BrandingHelper::urlConectAnuncioImagem($row['imagem_mobile_arquivo'] ?? null);
		return [
			'id'              => (int)($row['id'] ?? 0),
			'titulo'          => (string)($row['titulo'] ?? ''),
			'nomeAnunciante'  => (string)($row['nome_anunciante'] ?? ''),
			'imagemUrl'       => $imagem,
			'imagemMobileUrl' => $imagemMobile ?: $imagem,
			'linkTipo'        => (string)($row['link_tipo'] ?? 'url'),
			'linkDestino'     => self::montarUrlDestino($row),
			'slot'            => (string)($row['slot'] ?? ''),
		];
	}

	/** @param array<string,mixed> $row */
	public static function mapAdmin(array $row, ?array $metricas = null): array {
		$metricas = $metricas ?? CjAnuncioEvento::resumoPorAnuncio((int)($row['id'] ?? 0));
		$imp = (int)($metricas['impressoes'] ?? 0);
		$clk = (int)($metricas['cliques'] ?? 0);
		$ctr = $imp > 0 ? round(($clk / $imp) * 100, 2) : 0;
		$status = (string)($row['status'] ?? '');
		return [
			'id'               => (int)($row['id'] ?? 0),
			'titulo'           => (string)($row['titulo'] ?? ''),
			'nomeAnunciante'   => (string)($row['nome_anunciante'] ?? ''),
			'imagemUrl'        => BrandingHelper::urlConectAnuncioImagem($row['imagem_arquivo'] ?? null),
			'linkTipo'         => (string)($row['link_tipo'] ?? 'url'),
			'linkDestino'      => (string)($row['link_destino'] ?? ''),
			'whatsapp'         => (string)($row['whatsapp'] ?? ''),
			'slot'             => (string)($row['slot'] ?? ''),
			'slotLabel'        => self::SLOTS[self::slotLegado((string)($row['slot'] ?? ''))] ?? ($row['slot'] ?? ''),
			'uf'               => $row['uf'] ?? null,
			'cidadeId'         => isset($row['cidade_id']) ? (int)$row['cidade_id'] : null,
			'cidadeNome'       => (string)($row['cidade_nome'] ?? ''),
			'inicioEm'         => $row['inicio_em'] ?? null,
			'fimEm'            => $row['fim_em'] ?? null,
			'ordem'            => (int)($row['ordem'] ?? 0),
			'status'           => $status,
			'statusLabel'      => self::STATUS_LABELS[$status] ?? $status,
			'valorMensal'      => isset($row['valor_mensal']) ? (float)$row['valor_mensal'] : null,
			'motivoRejeicao'   => (string)($row['motivo_rejeicao'] ?? ''),
			'ownerTipo'        => (string)($row['owner_tipo'] ?? ''),
			'idEmpresa'        => isset($row['id_empresa']) ? (int)$row['id_empresa'] : null,
			'empresaNome'      => (string)($row['empresa_nome'] ?? ''),
			'impressoes'       => $imp,
			'cliques'          => $clk,
			'ctr'              => $ctr,
			'createdAt'        => (string)($row['created_at'] ?? ''),
		];
	}

	/** @param array<string,mixed> $row */
	public static function montarUrlDestino(array $row): string {
		$tipo = (string)($row['link_tipo'] ?? 'url');
		$dest = trim((string)($row['link_destino'] ?? ''));
		if ($tipo === 'whatsapp') {
			$num = preg_replace('/\D+/', '', (string)($row['whatsapp'] ?? $dest));
			if ($num === '') {
				return '';
			}
			if (strlen($num) <= 11) {
				$num = '55'.$num;
			}
			$texto = rawurlencode('Olá! Vi seu anúncio no Conecta Jovem.');
			return 'https://wa.me/'.$num.'?text='.$texto;
		}
		if ($tipo === 'instagram') {
			$handle = ltrim($dest, '@');
			if ($handle === '') {
				return '';
			}
			if (preg_match('#^https?://#i', $handle)) {
				return $handle;
			}
			return 'https://instagram.com/'.rawurlencode($handle);
		}
		if ($dest !== '' && !preg_match('#^https?://#i', $dest)) {
			return 'https://'.$dest;
		}
		return $dest;
	}

	/** @param array<string,mixed> $input */
	public static function validarPayload(array $input, bool $exigirImagem = true, bool $ignorarSlotsHabilitados = false): array {
		$titulo = trim((string)($input['titulo'] ?? ''));
		$nome = trim((string)($input['nome_anunciante'] ?? $input['nomeAnunciante'] ?? ''));
		$slot = self::slotLegado((string)($input['slot'] ?? 'footer_carousel'));
		$linkTipo = (string)($input['link_tipo'] ?? $input['linkTipo'] ?? 'url');
		$linkDestino = trim((string)($input['link_destino'] ?? $input['linkDestino'] ?? ''));
		$whatsapp = preg_replace('/\D+/', '', (string)($input['whatsapp'] ?? ''));

		if ($titulo === '') {
			return ['ok' => false, 'message' => 'Informe o título do anúncio.'];
		}
		if ($nome === '') {
			return ['ok' => false, 'message' => 'Informe o nome do anunciante.'];
		}
		if (!isset(self::SLOTS[$slot])) {
			return ['ok' => false, 'message' => 'Posição inválida.'];
		}
		if (!in_array($linkTipo, self::LINK_TIPOS, true)) {
			return ['ok' => false, 'message' => 'Tipo de link inválido.'];
		}
		if ($linkTipo === 'whatsapp' && strlen($whatsapp) < 10) {
			return ['ok' => false, 'message' => 'Informe um WhatsApp válido.'];
		}
		if ($linkTipo === 'instagram' && $linkDestino === '') {
			return ['ok' => false, 'message' => 'Informe o @ ou URL do Instagram.'];
		}
		if ($linkTipo === 'url' && $linkDestino === '') {
			return ['ok' => false, 'message' => 'Informe a URL de destino.'];
		}
		if ($exigirImagem && empty($input['imagem_arquivo']) && empty($input['imagemArquivo'])) {
			return ['ok' => false, 'message' => 'Envie a imagem do banner.'];
		}

		$config = CjAnuncioConfig::get();
		$habilitados = $config['slots_habilitados'] ?? [];
		if (
			!$ignorarSlotsHabilitados
			&& is_array($habilitados)
			&& !empty($habilitados)
			&& !in_array($slot, $habilitados, true)
		) {
			return ['ok' => false, 'message' => 'Esta posição não está habilitada no momento.'];
		}

		$uf = strtoupper(trim((string)($input['uf'] ?? '')));
		if ($uf !== '' && strlen($uf) !== 2) {
			$uf = '';
		}
		$cidadeId = (int)($input['cidade_id'] ?? $input['cidadeId'] ?? 0);

		return [
			'ok' => true,
			'data' => [
				'titulo'          => mb_substr($titulo, 0, 160),
				'nome_anunciante' => mb_substr($nome, 0, 160),
				'slot'            => $slot,
				'link_tipo'       => $linkTipo,
				'link_destino'    => mb_substr($linkDestino, 0, 500),
				'whatsapp'        => $whatsapp !== '' ? $whatsapp : null,
				'uf'              => $uf !== '' ? $uf : null,
				'cidade_id'       => $cidadeId > 0 ? $cidadeId : null,
				'ordem'           => (int)($input['ordem'] ?? 0),
				'inicio_em'       => self::parseDateTime($input['inicio_em'] ?? $input['inicioEm'] ?? null),
				'fim_em'          => self::parseDateTime($input['fim_em'] ?? $input['fimEm'] ?? null),
				'valor_mensal'    => isset($input['valor_mensal']) || isset($input['valorMensal'])
					? max(0, (float)($input['valor_mensal'] ?? $input['valorMensal']))
					: null,
			],
		];
	}

	public static function statusInicialEmpresa(): string {
		$config = CjAnuncioConfig::get();
		return !empty($config['requer_aprovacao_master']) ? 'pendente' : 'ativo';
	}

	public static function geoEmpresa(CjEmpresa $empresa): array {
		$cidadeId = (int)($empresa->cidade_id ?? 0);
		$uf = strtoupper(trim((string)($empresa->uf ?? '')));
		if ($cidadeId > 0) {
			$loc = ConectEnderecoHelper::localPorCidadeId($cidadeId);
			if ($loc['uf'] !== '') {
				$uf = $loc['uf'];
			}
		}
		return [
			'cidade_id' => $cidadeId > 0 ? $cidadeId : null,
			'uf'        => strlen($uf) === 2 ? $uf : null,
		];
	}

	public static function registrarEvento(
		int $anuncioId,
		string $tipo,
		?string $visitorId,
		?string $slot,
		?string $uf,
		?int $cidadeId
	): bool {
		if (!self::permitirEvento($visitorId)) {
			return false;
		}
		return CjAnuncioEvento::registrar($anuncioId, $tipo, $visitorId, $slot, $uf, $cidadeId);
	}

	private static function permitirEvento(?string $visitorId): bool {
		$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0');
		$key = md5($ip.'|'.trim((string)$visitorId));
		$dir = sys_get_temp_dir().'/conect_anuncio_rate';
		if (!is_dir($dir)) {
			@mkdir($dir, 0755, true);
		}
		$file = $dir.'/'.$key.'.json';
		$now = time();
		$data = ['times' => []];
		if (is_file($file)) {
			$decoded = json_decode((string)file_get_contents($file), true);
			if (is_array($decoded['times'] ?? null)) {
				$data = $decoded;
			}
		}
		$data['times'] = array_values(array_filter(
			$data['times'],
			static fn($t) => is_int($t) && $t > ($now - 3600)
		));
		if (count($data['times']) >= 200) {
			return false;
		}
		$data['times'][] = $now;
		@file_put_contents($file, json_encode($data));
		return true;
	}

	private static function parseDateTime($value): ?string {
		$value = trim((string)$value);
		if ($value === '') {
			return null;
		}
		$ts = strtotime($value);
		return $ts ? date('Y-m-d H:i:s', $ts) : null;
	}
}
