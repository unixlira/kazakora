#!/usr/bin/env python3
"""
Font Awesome só com os ícones que a loja usa (pedido 2026-10-21, PageSpeed).

As 3 fontes completas somam ~255 KB e entravam antes do 1º banner no celular.
Este script procura os nomes `fa-*` no código, gera as fontes reduzidas em
resources/fonts/fontawesome/ e o CSS resources/css/fontawesome-icones-usados.css.

O CSS usa unicode-range: ícone que não está na fonte reduzida (ícone novo no
código) cai sozinho na fonte completa, então nada some. Rodar de novo quando
usar ícone novo só deixa o carregamento leve outra vez.

Uso (precisa de fonttools e brotli):  python3 scripts/fontawesome-subset.py
"""
import pathlib
import re

from fontTools import subset
from fontTools.ttLib import TTFont

RAIZ = pathlib.Path(__file__).resolve().parent.parent
FA = RAIZ / 'node_modules/@fortawesome/fontawesome-free'
SAIDA = RAIZ / 'resources/fonts/fontawesome'
CSS = RAIZ / 'resources/css/fontawesome-icones-usados.css'

FONTES = [
    # arquivo, família, peso
    ('fa-solid-900', 'Font Awesome 7 Free', 900),
    ('fa-regular-400', 'Font Awesome 7 Free', 400),
    ('fa-brands-400', 'Font Awesome 7 Brands', 400),
]

css_fa = (FA / 'css/all.min.css').read_text()
codigo_por_nome = {}
for seletores, valor in re.findall(r'((?:\.fa-[a-z0-9-]+,?)+)\{--fa:"\\([0-9a-f]+)', css_fa):
    for nome in re.findall(r'\.fa-([a-z0-9-]+)', seletores):
        codigo_por_nome[nome] = int(valor, 16)

usados = set()
for pasta in ('resources/js', 'resources/views', 'app'):
    for arquivo in (RAIZ / pasta).rglob('*'):
        if arquivo.suffix in ('.vue', '.js', '.php') and 'vendor' not in arquivo.parts:
            usados.update(re.findall(r'fa-([a-z0-9]+(?:-[a-z0-9]+)*)', arquivo.read_text(errors='ignore')))

codigos = sorted({codigo_por_nome[n] for n in usados if n in codigo_por_nome})
print(f'{len(codigos)} ícones usados')

def faixa(cps):
    partes, inicio, fim = [], None, None
    for cp in cps:
        if inicio is None:
            inicio = fim = cp
        elif cp == fim + 1:
            fim = cp
        else:
            partes.append((inicio, fim))
            inicio = fim = cp
    if inicio is not None:
        partes.append((inicio, fim))
    return ','.join(f'U+{a:X}' if a == b else f'U+{a:X}-{b:X}' for a, b in partes)

regras = [
    '/* Gerado por scripts/fontawesome-subset.py: não editar à mão. */',
    '/* Font Awesome só com os ícones usados; o que não estiver aqui usa a fonte completa (unicode-range). */',
]
for arquivo, familia, peso in FONTES:
    origem = FA / f'webfonts/{arquivo}.woff2'
    fonte = TTFont(origem)
    tem = set(fonte.getBestCmap())
    meus = [cp for cp in codigos if cp in tem]
    if not meus:
        continue
    opcoes = subset.Options()
    opcoes.flavor = 'woff2'
    opcoes.layout_features = ['*']
    opcoes.notdef_outline = True
    sub = subset.Subsetter(opcoes)
    sub.populate(unicodes=meus)
    sub.subset(fonte)
    destino = SAIDA / f'{arquivo}-usados.woff2'
    fonte.flavor = 'woff2'
    fonte.save(destino)
    print(f'{arquivo}: {len(meus)} ícones, {origem.stat().st_size // 1024} KB -> {destino.stat().st_size // 1024} KB')
    regras.append(
        f'@font-face{{font-family:"{familia}";font-style:normal;font-weight:{peso};font-display:block;'
        f'src:url(../fonts/fontawesome/{arquivo}-usados.woff2) format("woff2");unicode-range:{faixa(meus)}}}'
    )

CSS.write_text('\n'.join(regras) + '\n')
