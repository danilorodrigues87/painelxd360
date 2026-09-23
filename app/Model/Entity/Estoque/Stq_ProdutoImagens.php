<?php

namespace App\Model\Entity\Estoque;

use App\Model\Db\Database;

class Stq_ProdutoImagens
{
    public $id;
    public $id_produto;
    public $imagem;
    public $principal;
    public $created_at;

    public static function getByProduto(int $idProduto)
    {
        return (new Database('stq_produto_imagens'))
            ->select(
                'id_produto = ' . $idProduto,
                'principal DESC, id ASC'
            );
    }

    public function cadastrar(): bool
    {
        $this->id = (new Database('stq_produto_imagens'))->insert([
            'id_produto' => $this->id_produto,
            'imagem'     => $this->imagem,
            'principal'  => $this->principal ?? 0,
            'created_at' => date('Y-m-d H:i:s')
        ]);

        return true;
    }

    public static function remover(int $id): bool
    {
        return (new Database('stq_produto_imagens'))
            ->delete('id = ' . $id);
    }
}
