import { test } from 'node:test';
import assert from 'node:assert/strict';
import { snapshot, instalarJornada } from '../resources/js/Shared/jornadaCarrinho.mjs';
test('PDP sem cesta não inventa vazio; retorno autoritativo vence props ausentes', () => {
 assert.equal(snapshot({product:{id:1}}),null);
 const handlers={}, sent=[];
 instalarJornada({on:(n,f)=>handlers[n]=f},{component:'Catalog/ProductDetailV2',props:{product:{id:1}}},e=>sent.push(e));
 handlers.start({detail:{visit:{method:'post',url:'/carrinho',data:{product_id:1,quantity:2}}}});
 handlers.success({detail:{page:{component:'Catalog/ProductDetailV2',props:{jornadaCarrinho:{currency:'BRL',subtotal:2000,discount:null,shipping:null,interest:null,total:null,items:[{product_id:1,quantity:2,unit_price:1000}]}}}}});
 assert.equal(sent.at(-1).jornada.depois.items[0].quantity,2);
 assert.equal(sent.at(-1).jornada.depois.subtotal,2000);
});
test('snapshot conserva IDs reais e desconhecidos, nunca dados pessoais', () => {
 const p = snapshot({items:[{product:{id:91,final_price:12.34,name:'private'},quantity:2}],total:24.68});
 assert.equal(p.shipping,null); assert.equal(p.interest,null); assert.equal(p.total,2468);
 assert.deepEqual(p.items,[{product_id:91,quantity:2,unit_price:1234}]);
 assert.equal(JSON.stringify(p).includes('private'),false);
});
test('intenção leva o ID de variante realmente enviado, sem inferir cesta futura', () => {
 const h={}; const sent=[];
 instalarJornada({on:(n,f)=>h[n]=f},{component:'Catalog/ProductDetailV2',props:{product:{id:1,final_price:10},variations:[{id:2,final_price:20}]}},e=>sent.push(e));
 h.start({detail:{visit:{method:'post',url:new URL('https://fixture.invalid/comprar-agora'),data:{product_id:2,quantity:3}}}});
 assert.equal(sent.at(-1).jornada.depois.items[0].product_id,2);
 assert.equal(sent.at(-1).jornada.depois.total,null);
});
test('intenção não vira sucesso em falha; sucesso usa resposta real', () => {
 const handlers={}; const sent=[];
 const router={on:(name,fn)=>{handlers[name]=fn;}};
 instalarJornada(router,{component:'Cart/Index',props:{items:[],total:0}},(e)=>sent.push(e));
 handlers.start({detail:{visit:{method:'post',url:new URL('https://fixture.invalid/carrinho'),data:{product_id:91,quantity:2,email:'private'}}}});
 assert.equal(sent.at(-1).jornada.estado,'intent');
 handlers.error?.({detail:{errors:{quantity:'invalid'}}});
 assert.equal(sent.filter(e=>e.evento==='carrinho_adicionar'&&e.jornada.estado==='observado').length,0);
 handlers.start({detail:{visit:{method:'post',url:new URL('https://fixture.invalid/carrinho'),data:{product_id:91,quantity:2}}}});
 handlers.success({detail:{page:{component:'Cart/Index',props:{items:[{product:{id:91,final_price:12.34},quantity:2}],total:24.68}}}});
 assert.equal(sent.at(-1).jornada.depois.total,2468);
 assert.equal(sent.at(-1).jornada.estado,'observado');
 assert.equal(JSON.stringify(sent).includes('private'),false);
});
