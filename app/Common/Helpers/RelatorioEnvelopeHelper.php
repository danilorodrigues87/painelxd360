<?php

namespace App\Common\Helpers;

/**
 * Envelope HTML compartilhado para relatórios (cabeçalho na impressão/PDF, oculto na tela via CSS).
 */
class RelatorioEnvelopeHelper {

	public static function cidadeEscolaLinha(array $escola): string {
		$cidadeRaw = trim((string)($escola['cidade'] ?? ''));
		$estadoRaw = trim((string)($escola['estado'] ?? ''));
		if ($cidadeRaw === '' && $estadoRaw === '') {
			return '';
		}
		if (ctype_digit($cidadeRaw) || ctype_digit($estadoRaw)) {
			try {
				$cid = ctype_digit($cidadeRaw)
					? \App\Model\Entity\EstadoCidades::getCidades('id = '.(int)$cidadeRaw)->fetchObject()
					: null;
				$est = ctype_digit($estadoRaw)
					? \App\Model\Entity\EstadoCidades::getEstados('id = '.(int)$estadoRaw)->fetchObject()
					: null;
				$parts = [];
				if ($cid && !empty($cid->nome)) {
					$parts[] = $cid->nome;
				} elseif ($cidadeRaw !== '' && !ctype_digit($cidadeRaw)) {
					$parts[] = $cidadeRaw;
				}
				if ($est && !empty($est->sigla)) {
					$parts[] = $est->sigla;
				} elseif ($estadoRaw !== '' && !ctype_digit($estadoRaw)) {
					$parts[] = $estadoRaw;
				}
				return implode(' - ', $parts);
			} catch (\Throwable $e) {
				return '';
			}
		}
		return trim($cidadeRaw.($estadoRaw !== '' ? ' - '.$estadoRaw : ''));
	}

	/**
	 * @param array<string, mixed> $meta
	 *   - escola, titulo, periodo, emitido_em, emitido_por, meta_linhas (string[])
	 *   - incluir_rodape (bool, default true)
	 *   - rodape_nota (string|null, default nota fiscal; null oculta a linha)
	 */
	public static function montar(string $conteudo, array $meta): string {
		$escola = $meta['escola'] ?? [];
		$logoUrl = htmlspecialchars(BrandingHelper::urlLogoEscola($escola['logo'] ?? null), ENT_QUOTES, 'UTF-8');
		$nomeEscola = htmlspecialchars((string)($escola['nome'] ?? 'Escola'), ENT_QUOTES, 'UTF-8');
		$cnpjEscola = htmlspecialchars((string)($escola['cpf_cnpj'] ?? ''), ENT_QUOTES, 'UTF-8');
		$siteEscola = trim((string)($escola['site'] ?? ''));
		if ($siteEscola === '') {
			$siteEscola = 'www.ctieducacional.com.br';
		}
		$siteEscola = htmlspecialchars($siteEscola, ENT_QUOTES, 'UTF-8');
		$cidadeLinha = htmlspecialchars(self::cidadeEscolaLinha($escola), ENT_QUOTES, 'UTF-8');

		$titulo = htmlspecialchars((string)($meta['titulo'] ?? 'Relatório'), ENT_QUOTES, 'UTF-8');
		$periodo = htmlspecialchars((string)($meta['periodo'] ?? ''), ENT_QUOTES, 'UTF-8');
		$emitidoEm = htmlspecialchars((string)($meta['emitido_em'] ?? ''), ENT_QUOTES, 'UTF-8');
		$emitidoPor = htmlspecialchars((string)($meta['emitido_por'] ?? ''), ENT_QUOTES, 'UTF-8');

		$cnpjHtml = $cnpjEscola !== '' ? '<div class="relatorio-meta-linha">CNPJ: '.$cnpjEscola.'</div>' : '';
		$cidadeHtml = $cidadeLinha !== '' ? '<div class="relatorio-meta-linha">'.$cidadeLinha.'</div>' : '';
		$porHtml = $emitidoPor !== '' ? ' · Emitido por: '.$emitidoPor : '';

		$metaExtra = '';
		foreach ($meta['meta_linhas'] ?? [] as $linha) {
			$metaExtra .= '<div class="relatorio-meta-linha">'.htmlspecialchars((string)$linha, ENT_QUOTES, 'UTF-8').'</div>';
		}

		$rodapeHtml = '';
		$incluirRodape = ($meta['incluir_rodape'] ?? true);
		if ($incluirRodape) {
			$notaRodape = array_key_exists('rodape_nota', $meta)
				? $meta['rodape_nota']
				: 'Documento informativo — sem valor fiscal.';
			$notaHtml = ($notaRodape !== null && $notaRodape !== '')
				? '<div class="relatorio-meta-linha relatorio-rodape-nota">'.htmlspecialchars((string)$notaRodape, ENT_QUOTES, 'UTF-8').'</div>'
				: '';
			$rodapeHtml = '
  <footer class="relatorio-rodape mt-3 pt-3 border-top text-center">
    <div class="relatorio-meta-linha">'.$siteEscola.'</div>
    '.$notaHtml.'
  </footer>';
		}

		return '
<div class="relatorio-financeiro-impressao">
  <header class="relatorio-cabecalho mb-3 pb-3 border-bottom">
    <div class="d-flex align-items-center flex-wrap gap-3">
      <img src="'.$logoUrl.'" alt="" class="relatorio-logo">
      <div class="relatorio-escola-info">
        <div class="relatorio-escola-nome">'.$nomeEscola.'</div>
        '.$cnpjHtml.'
        '.$cidadeHtml.'
      </div>
    </div>
    <div class="relatorio-titulo-bloco mt-3">
      <h4 class="relatorio-titulo mb-2">'.$titulo.'</h4>
      <div class="relatorio-meta-linha">Período: '.$periodo.'</div>
      <div class="relatorio-meta-linha">Data de emissão: '.$emitidoEm.$porHtml.'</div>
      '.$metaExtra.'
    </div>
  </header>
  '.$conteudo.$rodapeHtml.'
</div>';
	}
}
