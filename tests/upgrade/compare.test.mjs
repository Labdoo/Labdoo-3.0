import test from 'node:test';
import assert from 'node:assert/strict';
import {compare} from './compare.mjs';
const report=(tests)=>({stats:{unexpected:Object.values(tests).filter(x=>x==='unexpected').length},suites:[{title:'suite',specs:Object.entries(tests).map(([title,status])=>({title,tests:[{projectName:'desktop',status,expectedStatus:'passed'}]}))}]});
test('reports a newly failing test and an existing failure separately',()=>{
 const result=compare(report({a:'expected',b:'unexpected'}),report({a:'unexpected',b:'unexpected'}));
 assert.equal(result.regressions.length,1);assert.match(result.regressions[0].id,/a$/);assert.equal(result.after.unexpected,2);
});
test('skipping or removing a previously passing test is a regression',()=>{
 for(const status of ['skipped',null]){const result=compare(report({a:'expected'}),report(status?{a:status}:{}));assert.equal(result.regressions.length,1);assert.equal(result.missing.length,status?0:1);}
});
test('identifies recovered tests and new unmatched tests',()=>{
 const result=compare(report({a:'unexpected'}),report({a:'expected',b:'expected'}));assert.equal(result.recovered.length,1);assert.equal(result.missing.length,1);
});
