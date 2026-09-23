<?php

namespace App\Common\Helpers;

class PlanilhaHelper {

	public static function lerArquivo($caminho, $extensao){

		$extensao = strtolower(trim($extensao));

		if($extensao === 'csv'){
			return self::lerCsv($caminho);
		}

		if($extensao === 'xlsx'){
			return self::lerXlsx($caminho);
		}

		throw new \InvalidArgumentException('Formato de arquivo não suportado.');
	}

	private static function lerCsv($caminho){

		$linhas = [];
		$handle = fopen($caminho, 'r');

		if($handle === false){
			throw new \RuntimeException('Não foi possível ler o arquivo CSV.');
		}

		$primeiraLinha = fgets($handle);
		if($primeiraLinha === false){
			fclose($handle);
			return [];
		}

		$primeiraLinha = self::removerBom($primeiraLinha);
		$delimitador = self::detectarDelimitador($primeiraLinha);

		$linhas[] = str_getcsv($primeiraLinha, $delimitador);

		while(($dados = fgetcsv($handle, 0, $delimitador)) !== false){
			if(self::linhaVazia($dados)){
				continue;
			}
			$linhas[] = $dados;
		}

		fclose($handle);

		return $linhas;
	}

	private static function lerXlsx($caminho){

		if(!class_exists('ZipArchive')){
			throw new \RuntimeException('Extensão ZipArchive não está habilitada no PHP.');
		}

		$zip = new \ZipArchive();

		if($zip->open($caminho) !== true){
			throw new \RuntimeException('Arquivo XLSX inválido ou corrompido.');
		}

		$sharedStrings = self::lerSharedStrings($zip);
		$sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');

		if($sheetXml === false){
			$zip->close();
			throw new \RuntimeException('Planilha vazia ou sem aba principal.');
		}

		$zip->close();

		$xml = simplexml_load_string($sheetXml);

		if($xml === false || !isset($xml->sheetData->row)){
			return [];
		}

		$linhas = [];

		foreach($xml->sheetData->row as $row){

			$celulas = [];

			foreach($row->c as $cell){
				$referencia = (string)$cell['r'];
				$coluna     = preg_replace('/[0-9]+/', '', $referencia);
				$indice     = self::colunaParaIndice($coluna);
				$valor      = self::extrairValorCelula($cell, $sharedStrings);
				$celulas[$indice] = $valor;
			}

			if(count($celulas) === 0){
				continue;
			}

			$linha = self::normalizarLinha($celulas);

			if(self::linhaVazia($linha)){
				continue;
			}

			$linhas[] = $linha;
		}

		return $linhas;
	}

	private static function lerSharedStrings($zip){

		$sharedStrings = [];
		$sharedXml = $zip->getFromName('xl/sharedStrings.xml');

		if($sharedXml === false){
			return $sharedStrings;
		}

		$xml = simplexml_load_string($sharedXml);

		if($xml === false || !isset($xml->si)){
			return $sharedStrings;
		}

		foreach($xml->si as $si){

			if(isset($si->t)){
				$sharedStrings[] = (string)$si->t;
				continue;
			}

			$texto = '';

			if(isset($si->r)){
				foreach($si->r as $parte){
					$texto .= (string)$parte->t;
				}
			}

			$sharedStrings[] = $texto;
		}

		return $sharedStrings;
	}

	private static function extrairValorCelula($cell, $sharedStrings){

		if(isset($cell['t']) && (string)$cell['t'] === 's'){
			$indice = (int)$cell->v;
			return $sharedStrings[$indice] ?? '';
		}

		if(isset($cell['t']) && (string)$cell['t'] === 'inlineStr'){
			return (string)($cell->is->t ?? '');
		}

		return (string)($cell->v ?? '');
	}

	private static function detectarDelimitador($linha){
		$virgulas   = substr_count($linha, ',');
		$pontoVirgula = substr_count($linha, ';');
		return $pontoVirgula > $virgulas ? ';' : ',';
	}

	private static function removerBom($texto){
		if(strpos($texto, "\xEF\xBB\xBF") === 0){
			return substr($texto, 3);
		}
		return $texto;
	}

	private static function colunaParaIndice($letras){

		$letras = strtoupper($letras);
		$numero = 0;
		$tamanho = strlen($letras);

		for($i = 0; $i < $tamanho; $i++){
			$numero = ($numero * 26) + (ord($letras[$i]) - ord('A') + 1);
		}

		return $numero - 1;
	}

	private static function normalizarLinha($celulas, $totalColunas = 6){

		$linha = [];

		for($i = 0; $i < $totalColunas; $i++){
			$linha[] = trim($celulas[$i] ?? '');
		}

		return $linha;
	}

	private static function linhaVazia($linha){

		foreach($linha as $valor){
			if(trim((string)$valor) !== ''){
				return false;
			}
		}

		return true;
	}

	public static function pareceCabecalho($linha){

		$primeiro = strtolower(trim($linha[0] ?? ''));

		return in_array($primeiro, [
			'nome',
			'name',
			'cliente',
			'lead'
		]);
	}

}
