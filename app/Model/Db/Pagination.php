<?php

namespace App\Model\Db;

class Pagination{

	private $limit;
	private $results;
	private $pages;
	private $currentPage;

	public function __construct($results,$currentPage = 1, $limit = 10){
		$this->results = $results;
		$this->limit = $limit;
		$this->currentPage = (is_numeric($currentPage) and $currentPage > 0) ? $currentPage : 1;
		$this->calculate();
	}

	private function calculate(){
		//CALCULA O TOTAL DE PAGINAS
		$this->pages = $this->results > 0 ? ceil($this->results / $this->limit) : 1;

		//VERIFICA SE A PAGINA ATUAL NÃO EXCEDE O NUMERO DE PAGINAS
		$this->currentPage = $this->currentPage <= $this->pages ? $this->currentPage : $this->pages;
	}

	//RETORNA A CLAUSULA LIMIT
	public function getLimit(){
		$offset = ($this->limit * ($this->currentPage - 1));
		return $offset.','.$this->limit;
	}

	//RETORNA AS OPÇÕES DE PAGINAS DISPONIVEIS
	public function getPages(){
		//NÃO RETORNA PAGINAS VAZIAS
		if($this->pages == 1) return [];

		//PAGINAS
		$paginas = [];
		for ($i = 1; $i <= $this->pages; $i++) {
			$paginas[] = [
				'page' => $i,
				'current' => $i == $this->currentPage
			];
		}
		return $paginas;

	}
}