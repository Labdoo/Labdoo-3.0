import fs from 'node:fs';
import path from 'node:path';
export function cases(report){
 const found=new Map();
 function visit(suite,parents=[]){
  for(const s of suite.specs||[])for(const t of s.tests||[]){const id=[t.projectName,...parents,s.title].join(' / ');found.set(id,{id,status:t.status,expected:t.expectedStatus,errors:t.results?.flatMap(r=>r.errors||[]).map(e=>e.message)||[]});}
  for(const child of suite.suites||[])visit(child,[...parents,child.title]);
 }
 for(const s of report.suites||[])visit(s,[s.title]);return found;
}
export function compare(before,after){
 const a=cases(before),b=cases(after),rows=[];
 for(const id of new Set([...a.keys(),...b.keys()])){
  const left=a.get(id),right=b.get(id);rows.push({id,before:left?.status||'missing',after:right?.status||'missing',regression:left?.status==='expected'&&right?.status!=='expected',recovered:!!left&&left.status!=='expected'&&right?.status==='expected'});
 }
 return {before:before.stats,after:after.stats,regressions:rows.filter(r=>r.regression),recovered:rows.filter(r=>r.recovered),missing:rows.filter(r=>r.before==='missing'||r.after==='missing'),rows};
}
if(process.argv[1]&&path.resolve(process.argv[1])===new URL(import.meta.url).pathname){
 const [a,b]=process.argv.slice(2);if(!a||!b)throw Error('Usage: node compare.mjs before/results.json after/results.json');
 const result=compare(JSON.parse(fs.readFileSync(a)),JSON.parse(fs.readFileSync(b)));console.log(JSON.stringify(result,null,2));
 process.exitCode=result.regressions.length||result.missing.length||result.after.unexpected||result.after.flaky?1:0;
}
