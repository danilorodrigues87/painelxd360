<?php

namespace App\Common\Helpers;

use App\Common\Environment;

class GooglePlacesHelper {

	private const API_URL = 'https://places.googleapis.com/v1/places:searchText';
	private const RATE_WINDOW = 3600;
	private const RATE_MAX = 80;
	private const CACHE_VIEWPORT_TTL = 86400;
	private const CACHE_SESSAO_TTL = 3600;
	private const MAX_PAGINAS_AUTO = 3;

	private const QUERY_EMPRESAS_CIDADE = 'comércio serviços lojas empresas estabelecimentos';

	/** @var list<array{label:string,query:string}> */
	private const CATEGORIAS_CIDADE = [
		['label' => 'Comércio e lojas',           'query' => 'comércio lojas varejo'],
		['label' => 'Serviços gerais',            'query' => 'serviços prestadores'],
		['label' => 'Restaurantes e bares',       'query' => 'restaurantes bares lanchonetes'],
		['label' => 'Mercados e padarias',        'query' => 'supermercados mercados padarias'],
		['label' => 'Farmácias',                  'query' => 'farmácias drogarias'],
		['label' => 'Clínicas e consultórios',    'query' => 'clínicas consultórios odontologia'],
		['label' => 'Oficinas e autopeças',       'query' => 'oficinas mecânicas autopeças'],
		['label' => 'Salões e beleza',            'query' => 'salões beleza barbearias'],
		['label' => 'Escolas e cursos',           'query' => 'escolas cursos educação'],
		['label' => 'Hotéis e pousadas',          'query' => 'hotéis pousadas hospedagem'],
		['label' => 'Construção',                 'query' => 'construção materiais de construção'],
		['label' => 'Contabilidade e advocacia',  'query' => 'contabilidade advocacia'],
		['label' => 'Indústria',                  'query' => 'indústria fábricas'],
		['label' => 'Postos de combustível',      'query' => 'postos combustível'],
		['label' => 'Academias',                  'query' => 'academias fitness'],
	];

	/** @var list<string> */
	private const TIPOS_IGNORAR = [
		'locality',
		'administrative_area_level_1',
		'administrative_area_level_2',
		'administrative_area_level_3',
		'administrative_area_level_4',
		'administrative_area_level_5',
		'country',
		'political',
		'postal_code',
		'route',
		'street_address',
		'premise',
		'subpremise',
		'plus_code',
		'neighborhood',
		'sublocality',
		'sublocality_level_1',
		'sublocality_level_2',
		'colloquial_area',
		'archipelago',
		'continent',
		'geocode',
		'intersection',
	];

	/** @var list<string> */
	private const PALAVRAS_NEGOCIO = [
		'padaria', 'restaurante', 'clínica', 'clinica', 'mercado', 'farmácia', 'farmacia',
		'loja', 'hotel', 'bar', 'salão', 'salao', 'oficina', 'escola', 'igreja', 'posto',
		'banco', 'academia', 'pet', 'veterinár', 'veterinar', 'dentista', 'odontol',
		'advogad', 'contabil', 'constru', 'imobili', 'supermercado', 'açougue', 'acougue',
		'pizzaria', 'lanchonete', 'distribuidor', 'indústria', 'industria', 'fábrica', 'fabrica',
		'consultório', 'consultorio', 'hospital', 'laboratório', 'laboratorio', 'ótica', 'otica',
	];

	public static function apiKey(): string {
		Environment::load(__DIR__.'/../../../');
		return trim((string)(Environment::get('GOOGLE_PLACES_API_KEY') ?: ''));
	}

	public static function configurado(): bool {
		return self::apiKey() !== '';
	}

	/** @return list<array{label:string,query:string}> */
	public static function getCategoriasCidade(): array {
		return self::CATEGORIAS_CIDADE;
	}

	public static function pareceConsultaCidade(string $query): bool {
		return self::detectarConsultaCidade($query);
	}

	/**
	 * @param array{estrategia?:string,pageToken?:?string,categoriaIndex?:?int} $opts
	 * @return array{success:bool,message?:string,items?:array,nextPageToken?:string|null,modo?:string,paginas?:int,requisicoes?:int,categoria?:?string}
	 */
	public static function searchAvancado(string $query, array $opts = []): array {
		$estrategia = (string)($opts['estrategia'] ?? 'pagina');
		$pageToken = isset($opts['pageToken']) ? (string)$opts['pageToken'] : null;
		if ($pageToken === '') {
			$pageToken = null;
		}
		$categoriaIndex = isset($opts['categoriaIndex']) ? (int)$opts['categoriaIndex'] : null;

		if ($estrategia === 'todas_paginas') {
			return self::buscarTodasPaginas($query);
		}
		if ($estrategia === 'categorias') {
			return self::buscarVariasCategorias($query, false);
		}
		if ($estrategia === 'categorias_todas_paginas') {
			return self::buscarVariasCategorias($query, true);
		}
		if ($estrategia === 'categoria') {
			return self::buscarUmaCategoria($query, $categoriaIndex ?? 0, $pageToken, false);
		}
		if ($estrategia === 'categoria_todas_paginas') {
			return self::buscarUmaCategoria($query, $categoriaIndex ?? 0, null, true);
		}

		return self::searchText($query, $pageToken);
	}

	/**
	 * @return array{success:bool,message?:string,items?:array,nextPageToken?:string|null,modo?:string}
	 */
	public static function searchText(string $query, ?string $pageToken = null): array {
		$key = self::apiKey();
		if ($key === '') {
			return ['success' => false, 'message' => 'Configure GOOGLE_PLACES_API_KEY no .env do painel.'];
		}
		$query = trim($query);
		if (mb_strlen($query) < 3) {
			return ['success' => false, 'message' => 'Informe pelo menos 3 caracteres na busca.'];
		}

		$pageToken = ($pageToken !== null && trim($pageToken) !== '') ? trim($pageToken) : null;
		$modoCidade = self::detectarConsultaCidade($query);

		if ($modoCidade) {
			return self::searchEmpresasPorCidade($query, $pageToken);
		}

		if ($pageToken !== null) {
			$sessao = self::carregarSessaoBusca(self::chaveSessao($query));
			if ($sessao !== null) {
				return self::executarSearchText($sessao['body'], $pageToken, 'geral');
			}
		}

		$body = self::corpoBuscaGeral($query);
		self::salvarSessaoBusca(self::chaveSessao($query), $body);
		return self::executarSearchText($body, $pageToken, 'geral');
	}

	/**
	 * @return array{success:bool,message?:string,items?:array,nextPageToken?:string|null,modo?:string}
	 */
	private static function searchEmpresasPorCidade(string $query, ?string $pageToken): array {
		$cidadeNorm = self::normalizarCidade($query);
		$chave = self::chaveSessao($cidadeNorm);

		if ($pageToken !== null) {
			$sessao = self::carregarSessaoBusca($chave);
			if ($sessao !== null) {
				return self::executarSearchText($sessao['body'], $pageToken, 'cidade');
			}
			return [
				'success' => false,
				'message' => 'Sessão de paginação expirada. Faça a busca inicial novamente.',
			];
		}

		$cacheKey = md5(mb_strtolower($cidadeNorm));
		$viewport = self::carregarCacheViewport($cacheKey);
		if ($viewport === null) {
			if (!self::permitirRequisicao()) {
				return ['success' => false, 'message' => 'Limite de buscas Google atingido (30/hora). Tente mais tarde.'];
			}
			$viewport = self::buscarViewportCidade($cidadeNorm, $cacheKey);
		}
		if ($viewport === null) {
			return [
				'success' => false,
				'message' => 'Não foi possível localizar a cidade "'.$query.'". Tente "Guapiara SP" ou "Guapiara, SP".',
			];
		}

		$body = self::corpoBuscaCidade($viewport, null);
		self::salvarSessaoBusca($chave, $body);

		return self::executarSearchText($body, null, 'cidade');
	}

	/** @return array<string,mixed> */
	private static function corpoBuscaGeral(string $query): array {
		return [
			'textQuery'      => $query,
			'languageCode'   => 'pt-BR',
			'regionCode'     => 'BR',
			'pageSize'       => 20,
			'includePureServiceAreaBusinesses' => true,
		];
	}

	/** @param array{low:array{latitude:float,longitude:float},high:array{latitude:float,longitude:float}} $viewport */
	private static function corpoBuscaCidade(array $viewport, ?string $textQuery = null): array {
		return [
			'textQuery'      => $textQuery ?? self::QUERY_EMPRESAS_CIDADE,
			'languageCode'   => 'pt-BR',
			'regionCode'     => 'BR',
			'pageSize'       => 20,
			'includePureServiceAreaBusinesses' => true,
			'locationRestriction' => [
				'rectangle' => $viewport,
			],
		];
	}

	/**
	 * @return array{success:bool,message?:string,items?:array,nextPageToken?:string|null,modo?:string,paginas?:int,requisicoes?:int}
	 */
	private static function buscarTodasPaginas(string $query): array {
		$query = trim($query);
		if (mb_strlen($query) < 3) {
			return ['success' => false, 'message' => 'Informe pelo menos 3 caracteres na busca.'];
		}

		$allItems = [];
		$pageToken = null;
		$paginas = 0;
		$modo = 'geral';
		$ultimoErro = null;

		do {
			$result = self::searchText($query, $pageToken);
			if (empty($result['success'])) {
				$ultimoErro = (string)($result['message'] ?? 'Falha na busca Google.');
				if ($paginas === 0) {
					return $result;
				}
				break;
			}
			$modo = (string)($result['modo'] ?? 'geral');
			foreach (($result['items'] ?? []) as $item) {
				$pid = (string)($item['placeId'] ?? '');
				if ($pid !== '') {
					$allItems[$pid] = $item;
				}
			}
			$pageToken = $result['nextPageToken'] ?? null;
			$paginas++;
			if ($pageToken !== null && $paginas < self::MAX_PAGINAS_AUTO) {
				usleep(350000);
			}
		} while ($pageToken !== null && $paginas < self::MAX_PAGINAS_AUTO);

		return [
			'success'       => true,
			'items'         => array_values($allItems),
			'nextPageToken' => null,
			'modo'          => $modo,
			'paginas'       => $paginas,
			'requisicoes'   => $paginas,
			'message'       => $ultimoErro,
		];
	}

	/**
	 * @return array{success:bool,message?:string,items?:array,modo?:string,requisicoes?:int,categorias?:int}
	 */
	private static function buscarVariasCategorias(string $query, bool $todasPaginas): array {
		if (!self::detectarConsultaCidade($query)) {
			return [
				'success' => false,
				'message' => 'Importação por categorias exige busca só com o nome da cidade (ex.: Guapiara SP).',
			];
		}

		$viewport = self::viewportDaCidade($query);
		if ($viewport === null) {
			return ['success' => false, 'message' => 'Não foi possível localizar a cidade informada.'];
		}

		$allItems = [];
		$requisicoes = 0;
		$ultimoErro = null;

		foreach (self::CATEGORIAS_CIDADE as $idx => $cat) {
			$result = self::executarBuscaCategoria($query, $viewport, $idx, $todasPaginas);
			if (empty($result['success'])) {
				$ultimoErro = (string)($result['message'] ?? '');
				if (str_contains($ultimoErro, 'Limite de buscas')) {
					break;
				}
				continue;
			}
			$requisicoes += (int)($result['requisicoes'] ?? 1);
			foreach (($result['items'] ?? []) as $item) {
				$pid = (string)($item['placeId'] ?? '');
				if ($pid !== '') {
					$allItems[$pid] = $item;
				}
			}
		}

		if ($allItems === [] && $ultimoErro !== null) {
			return ['success' => false, 'message' => $ultimoErro];
		}

		return [
			'success'     => true,
			'items'       => array_values($allItems),
			'modo'        => 'cidade',
			'requisicoes' => $requisicoes,
			'categorias'  => count(self::CATEGORIAS_CIDADE),
		];
	}

	/**
	 * @return array{success:bool,message?:string,items?:array,nextPageToken?:string|null,modo?:string,paginas?:int,requisicoes?:int,categoria?:string}
	 */
	private static function buscarUmaCategoria(string $query, int $categoriaIndex, ?string $pageToken, bool $todasPaginas): array {
		if (!self::detectarConsultaCidade($query)) {
			return [
				'success' => false,
				'message' => 'Importação por categorias exige busca só com o nome da cidade (ex.: Guapiara SP).',
			];
		}
		if ($categoriaIndex < 0 || $categoriaIndex >= count(self::CATEGORIAS_CIDADE)) {
			return ['success' => false, 'message' => 'Categoria inválida.'];
		}

		$viewport = self::viewportDaCidade($query);
		if ($viewport === null) {
			return ['success' => false, 'message' => 'Não foi possível localizar a cidade informada.'];
		}

		if ($todasPaginas) {
			return self::executarBuscaCategoria($query, $viewport, $categoriaIndex, true);
		}

		$cat = self::CATEGORIAS_CIDADE[$categoriaIndex];
		$chave = self::chaveSessao(self::normalizarCidade($query).'|cat|'.$categoriaIndex);
		if ($pageToken !== null) {
			$sessao = self::carregarSessaoBusca($chave);
			if ($sessao !== null) {
				$result = self::executarSearchText($sessao['body'], $pageToken, 'cidade');
				$result['categoria'] = $cat['label'];
				$result['requisicoes'] = 1;
				return $result;
			}
			return ['success' => false, 'message' => 'Sessão de paginação expirada. Refaça a importação.'];
		}

		$body = self::corpoBuscaCidade($viewport, $cat['query']);
		self::salvarSessaoBusca($chave, $body);
		$result = self::executarSearchText($body, null, 'cidade');
		$result['categoria'] = $cat['label'];
		$result['requisicoes'] = 1;
		return $result;
	}

	/**
	 * @param array{low:array{latitude:float,longitude:float},high:array{latitude:float,longitude:float}} $viewport
	 * @return array{success:bool,message?:string,items?:array,paginas?:int,requisicoes?:int,categoria?:string}
	 */
	private static function executarBuscaCategoria(string $query, array $viewport, int $categoriaIndex, bool $todasPaginas): array {
		$cat = self::CATEGORIAS_CIDADE[$categoriaIndex];
		$body = self::corpoBuscaCidade($viewport, $cat['query']);
		$chave = self::chaveSessao(self::normalizarCidade($query).'|cat|'.$categoriaIndex);
		self::salvarSessaoBusca($chave, $body);

		$allItems = [];
		$pageToken = null;
		$paginas = 0;
		$ultimoErro = null;

		do {
			$result = self::executarSearchText($body, $pageToken, 'cidade');
			if (empty($result['success'])) {
				$ultimoErro = (string)($result['message'] ?? '');
				if ($paginas === 0) {
					return $result;
				}
				break;
			}
			foreach (($result['items'] ?? []) as $item) {
				$pid = (string)($item['placeId'] ?? '');
				if ($pid !== '') {
					$allItems[$pid] = $item;
				}
			}
			$pageToken = $result['nextPageToken'] ?? null;
			$paginas++;
			if ($pageToken !== null && $paginas < self::MAX_PAGINAS_AUTO) {
				usleep(350000);
			}
		} while ($todasPaginas && $pageToken !== null && $paginas < self::MAX_PAGINAS_AUTO);

		if ($allItems === [] && $ultimoErro !== null) {
			return ['success' => false, 'message' => $ultimoErro];
		}

		return [
			'success'     => true,
			'items'       => array_values($allItems),
			'paginas'     => $paginas,
			'requisicoes' => $paginas,
			'categoria'   => $cat['label'],
		];
	}

	/**
	 * @return array{low:array{latitude:float,longitude:float},high:array{latitude:float,longitude:float}}|null
	 */
	private static function viewportDaCidade(string $query): ?array {
		$cidadeNorm = self::normalizarCidade($query);
		$cacheKey = md5(mb_strtolower($cidadeNorm));
		$viewport = self::carregarCacheViewport($cacheKey);
		if ($viewport !== null) {
			return $viewport;
		}
		if (!self::permitirRequisicao()) {
			return null;
		}
		return self::buscarViewportCidade($cidadeNorm, $cacheKey);
	}

	/**
	 * @param array<string,mixed> $body
	 * @return array{success:bool,message?:string,items?:array,nextPageToken?:string|null,modo?:string}
	 */
	private static function executarSearchText(array $body, ?string $pageToken, string $modo): array {
		if (!self::permitirRequisicao()) {
			return ['success' => false, 'message' => 'Limite de buscas Google atingido ('.self::RATE_MAX.'/hora). Tente mais tarde.'];
		}

		$reqBody = $body;
		if ($pageToken !== null) {
			$reqBody['pageToken'] = $pageToken;
		}

		$fieldMask = implode(',', [
			'places.id',
			'places.displayName',
			'places.formattedAddress',
			'places.nationalPhoneNumber',
			'places.internationalPhoneNumber',
			'places.googleMapsUri',
			'places.websiteUri',
			'places.rating',
			'places.types',
			'places.primaryType',
			'nextPageToken',
		]);

		$response = self::httpPost(self::API_URL, $reqBody, [
			'Content-Type: application/json',
			'X-Goog-Api-Key: '.self::apiKey(),
			'X-Goog-FieldMask: '.$fieldMask,
		]);

		if (!$response['ok']) {
			return ['success' => false, 'message' => $response['message'] ?? 'Falha ao consultar Google Places.'];
		}

		$data = $response['json'];
		if (!is_array($data)) {
			return ['success' => false, 'message' => 'Resposta inválida da API Google.'];
		}

		if (!empty($data['error']['message'])) {
			return ['success' => false, 'message' => (string)$data['error']['message']];
		}

		$items = [];
		foreach (($data['places'] ?? []) as $place) {
			if (!is_array($place)) {
				continue;
			}
			$normalized = self::normalizarPlace($place, $modo === 'cidade');
			if ($normalized !== null) {
				$items[] = $normalized;
			}
		}

		return [
			'success'        => true,
			'items'          => $items,
			'nextPageToken'  => isset($data['nextPageToken']) ? (string)$data['nextPageToken'] : null,
			'modo'           => $modo,
		];
	}

	private static function detectarConsultaCidade(string $query): bool {
		$q = mb_strtolower(trim($query));
		if ($q === '') {
			return false;
		}
		foreach (self::PALAVRAS_NEGOCIO as $palavra) {
			if (mb_strpos($q, $palavra) !== false) {
				return false;
			}
		}
		if (preg_match('/\bem\s+\S/u', $q)) {
			return false;
		}
		return (bool)preg_match(
			'/^[\p{L}\s\-\.\']+(?:[\s,\-\/]+(?:ac|al|ap|am|ba|ce|df|es|go|ma|mt|ms|mg|pa|pb|pr|pe|pi|rj|rn|rs|ro|rr|sc|sp|se|to))?$/iu',
			$q
		);
	}

	private static function normalizarCidade(string $query): string {
		$q = trim(preg_replace('/\s+/u', ' ', $query) ?: $query);
		$q = preg_replace('/\s*[-\/]\s*/u', ', ', $q) ?: $q;

		if (preg_match('/^(.+?)[,\s]+(ac|al|ap|am|ba|ce|df|es|go|ma|mt|ms|mg|pa|pb|pr|pe|pi|rj|rn|rs|ro|rr|sc|sp|se|to)$/iu', $q, $m)) {
			$cidade = self::titulo(trim($m[1]));
			$uf = strtoupper($m[2]);
			return $cidade.', '.$uf.', Brasil';
		}

		if (!preg_match('/brasil/iu', $q)) {
			$q .= ', Brasil';
		}
		return self::titulo($q);
	}

	private static function titulo(string $texto): string {
		return mb_convert_case($texto, MB_CASE_TITLE, 'UTF-8');
	}

	/**
	 * @return array{low:array{latitude:float,longitude:float},high:array{latitude:float,longitude:float}}|null
	 */
	private static function buscarViewportCidade(string $cidadeNorm, string $cacheKey): ?array {
		$body = [
			'textQuery'    => $cidadeNorm,
			'languageCode' => 'pt-BR',
			'regionCode'   => 'BR',
			'pageSize'     => 3,
		];
		$fieldMask = 'places.viewport,places.location,places.types,places.primaryType';

		$response = self::httpPost(self::API_URL, $body, [
			'Content-Type: application/json',
			'X-Goog-Api-Key: '.self::apiKey(),
			'X-Goog-FieldMask: '.$fieldMask,
		]);

		if (!$response['ok'] || !is_array($response['json'])) {
			return null;
		}

		foreach (($response['json']['places'] ?? []) as $place) {
			if (!is_array($place)) {
				continue;
			}
			$viewport = self::extrairViewport($place);
			if ($viewport !== null) {
				$viewport = self::expandirViewport($viewport);
				self::salvarCacheViewport($cacheKey, $viewport);
				return $viewport;
			}
		}

		return null;
	}

	/** @param array<string,mixed> $place */
	private static function extrairViewport(array $place): ?array {
		if (isset($place['viewport']) && is_array($place['viewport'])) {
			$low = $place['viewport']['low'] ?? null;
			$high = $place['viewport']['high'] ?? null;
			if (is_array($low) && is_array($high)
				&& isset($low['latitude'], $low['longitude'], $high['latitude'], $high['longitude'])) {
				return [
					'low'  => [
						'latitude'  => (float)$low['latitude'],
						'longitude' => (float)$low['longitude'],
					],
					'high' => [
						'latitude'  => (float)$high['latitude'],
						'longitude' => (float)$high['longitude'],
					],
				];
			}
		}

		if (isset($place['location']) && is_array($place['location'])
			&& isset($place['location']['latitude'], $place['location']['longitude'])) {
			$lat = (float)$place['location']['latitude'];
			$lng = (float)$place['location']['longitude'];
			$delta = 0.06;
			return [
				'low'  => ['latitude' => $lat - $delta, 'longitude' => $lng - $delta],
				'high' => ['latitude' => $lat + $delta, 'longitude' => $lng + $delta],
			];
		}

		return null;
	}

	/**
	 * @param array{low:array{latitude:float,longitude:float},high:array{latitude:float,longitude:float}} $viewport
	 * @return array{low:array{latitude:float,longitude:float},high:array{latitude:float,longitude:float}}
	 */
	private static function expandirViewport(array $viewport): array {
		$latSpan = abs($viewport['high']['latitude'] - $viewport['low']['latitude']);
		$lngSpan = abs($viewport['high']['longitude'] - $viewport['low']['longitude']);
		$minSpan = 0.04;
		$padLat = max(0.01, ($minSpan - $latSpan) / 2);
		$padLng = max(0.01, ($minSpan - $lngSpan) / 2);

		return [
			'low'  => [
				'latitude'  => $viewport['low']['latitude'] - $padLat,
				'longitude' => $viewport['low']['longitude'] - $padLng,
			],
			'high' => [
				'latitude'  => $viewport['high']['latitude'] + $padLat,
				'longitude' => $viewport['high']['longitude'] + $padLng,
			],
		];
	}

	/** @param array<string,mixed> $place */
	private static function normalizarPlace(array $place, bool $modoCidade): ?array {
		if ($modoCidade && self::deveIgnorarPlace($place)) {
			return null;
		}

		$rawId = (string)($place['id'] ?? '');
		$placeId = str_starts_with($rawId, 'places/') ? substr($rawId, 7) : $rawId;
		if ($placeId === '') {
			return null;
		}

		$nome = '';
		if (isset($place['displayName']) && is_array($place['displayName'])) {
			$nome = trim((string)($place['displayName']['text'] ?? ''));
		}
		if ($nome === '') {
			$nome = 'Estabelecimento';
		}

		$telefone = trim((string)($place['nationalPhoneNumber'] ?? ''));
		if ($telefone === '') {
			$telefone = trim((string)($place['internationalPhoneNumber'] ?? ''));
		}
		$digits = preg_replace('/\D+/', '', $telefone) ?: '';
		if ($digits !== '' && strlen($digits) >= 10 && strlen($digits) <= 11) {
			$digits = '55'.$digits;
		}

		$nota = isset($place['rating']) ? (float)$place['rating'] : null;

		return [
			'placeId'         => $placeId,
			'nome'            => $nome,
			'endereco'        => trim((string)($place['formattedAddress'] ?? '')),
			'telefone'        => $telefone,
			'whatsappDigits'  => $digits,
			'whatsappUrl'     => $digits !== '' ? 'https://wa.me/'.$digits : '',
			'mapsUrl'         => trim((string)($place['googleMapsUri'] ?? '')),
			'site'            => trim((string)($place['websiteUri'] ?? '')),
			'nota'            => $nota,
		];
	}

	/** @param array<string,mixed> $place */
	private static function deveIgnorarPlace(array $place): bool {
		$tipos = [];
		if (!empty($place['primaryType'])) {
			$tipos[] = (string)$place['primaryType'];
		}
		if (!empty($place['types']) && is_array($place['types'])) {
			foreach ($place['types'] as $t) {
				$tipos[] = (string)$t;
			}
		}
		$tipos = array_unique(array_filter($tipos));
		if ($tipos === []) {
			return false;
		}
		foreach ($tipos as $tipo) {
			if (!in_array($tipo, self::TIPOS_IGNORAR, true)) {
				return false;
			}
		}
		return true;
	}

	private static function chaveSessao(string $query): string {
		return md5(mb_strtolower(trim($query)));
	}

	/** @param array<string,mixed> $body */
	private static function salvarSessaoBusca(string $chave, array $body): void {
		$dir = sys_get_temp_dir().'/prospeccao_places_sessao';
		if (!is_dir($dir)) {
			@mkdir($dir, 0755, true);
		}
		@file_put_contents($dir.'/'.$chave.'.json', json_encode([
			'body'    => $body,
			'expires' => time() + self::CACHE_SESSAO_TTL,
		]));
	}

	/** @return array{body:array<string,mixed>}|null */
	private static function carregarSessaoBusca(string $chave): ?array {
		$file = sys_get_temp_dir().'/prospeccao_places_sessao/'.$chave.'.json';
		if (!is_file($file)) {
			return null;
		}
		$data = json_decode((string)file_get_contents($file), true);
		if (!is_array($data) || empty($data['body']) || !is_array($data['body'])) {
			return null;
		}
		if (!empty($data['expires']) && (int)$data['expires'] < time()) {
			@unlink($file);
			return null;
		}
		return ['body' => $data['body']];
	}

	/**
	 * @param array{low:array{latitude:float,longitude:float},high:array{latitude:float,longitude:float}} $viewport
	 */
	private static function salvarCacheViewport(string $chave, array $viewport): void {
		$dir = sys_get_temp_dir().'/prospeccao_places_viewport';
		if (!is_dir($dir)) {
			@mkdir($dir, 0755, true);
		}
		@file_put_contents($dir.'/'.$chave.'.json', json_encode([
			'viewport' => $viewport,
			'expires'  => time() + self::CACHE_VIEWPORT_TTL,
		]));
	}

	/** @return array{low:array{latitude:float,longitude:float},high:array{latitude:float,longitude:float}}|null */
	private static function carregarCacheViewport(string $chave): ?array {
		$file = sys_get_temp_dir().'/prospeccao_places_viewport/'.$chave.'.json';
		if (!is_file($file)) {
			return null;
		}
		$data = json_decode((string)file_get_contents($file), true);
		if (!is_array($data) || empty($data['viewport']) || !is_array($data['viewport'])) {
			return null;
		}
		if (!empty($data['expires']) && (int)$data['expires'] < time()) {
			@unlink($file);
			return null;
		}
		return $data['viewport'];
	}

	/** @param array<string,mixed> $body */
	private static function httpPost(string $url, array $body, array $headers): array {
		$ch = curl_init($url);
		if ($ch === false) {
			return ['ok' => false, 'message' => 'cURL indisponível.'];
		}
		curl_setopt_array($ch, [
			CURLOPT_POST           => true,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 30,
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
		]);
		$raw = curl_exec($ch);
		$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$err = curl_error($ch);
		curl_close($ch);

		if ($raw === false) {
			return ['ok' => false, 'message' => $err !== '' ? $err : 'Erro de rede.'];
		}

		$json = json_decode((string)$raw, true);
		if ($code >= 400) {
			$msg = is_array($json) && !empty($json['error']['message'])
				? (string)$json['error']['message']
				: 'HTTP '.$code;
			return ['ok' => false, 'message' => $msg, 'json' => $json];
		}

		return ['ok' => true, 'json' => $json];
	}

	private static function permitirRequisicao(): bool {
		$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0');
		$dir = sys_get_temp_dir().'/prospeccao_places_rate';
		if (!is_dir($dir)) {
			@mkdir($dir, 0755, true);
		}
		$file = $dir.'/'.md5($ip).'.json';
		$now = time();
		$data = ['times' => []];
		if (is_file($file)) {
			$decoded = json_decode((string)file_get_contents($file), true);
			if (is_array($decoded) && isset($decoded['times']) && is_array($decoded['times'])) {
				$data = $decoded;
			}
		}
		$data['times'] = array_values(array_filter(
			$data['times'],
			static fn($t) => is_int($t) && $t > ($now - self::RATE_WINDOW)
		));
		if (count($data['times']) >= self::RATE_MAX) {
			return false;
		}
		$data['times'][] = $now;
		@file_put_contents($file, json_encode($data));
		return true;
	}
}
