#!/usr/bin/env python3
"""Gera phpunit.fatia.xml com só a fatia N de M dos arquivos de teste (CI em paralelo, 25/11/2026).

Os arquivos são repartidos pelo tamanho (o maior vai para a fatia mais leve), para
as fatias terminarem juntas. Uso: python3 scripts/ci/fatia-testes.py <fatia> <total>
"""
import pathlib
import re
import sys

fatia, total = int(sys.argv[1]), int(sys.argv[2])
arquivos = sorted(pathlib.Path('tests').rglob('*Test.php'), key=lambda p: (-p.stat().st_size, str(p)))
pesos = [0] * total
grupos = [[] for _ in range(total)]
for arq in arquivos:
    i = pesos.index(min(pesos))
    grupos[i].append(arq)
    pesos[i] += arq.stat().st_size

meus = sorted(grupos[fatia])
xml = pathlib.Path('phpunit.xml').read_text()
suite = '<testsuites>\n        <testsuite name="Fatia">\n' + ''.join(f'            <file>{p}</file>\n' for p in meus) + '        </testsuite>\n    </testsuites>'
xml = re.sub(r'<testsuites>.*?</testsuites>', suite, xml, flags=re.S)
pathlib.Path('phpunit.fatia.xml').write_text(xml)
print(f'Fatia {fatia + 1} de {total}: {len(meus)} arquivos de teste')
