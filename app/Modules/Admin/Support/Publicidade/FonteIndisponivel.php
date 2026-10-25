<?php

namespace App\Modules\Admin\Support\Publicidade;

use RuntimeException;

/** A plataforma não respondeu (token vencido, limite da API). A mensagem vai para a tela. */
class FonteIndisponivel extends RuntimeException {}
