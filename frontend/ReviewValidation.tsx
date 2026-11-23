import {domId,roles} from './FieldRow';
import type {Registry,Session} from './types';

type Issue=Session['validation']['errors'][number];
const sections:Record<string,{id:string;label:string}>={
  declaration:{id:'section-declaration',label:'Поставка и ДТС'},party:{id:'section-party',label:'Участники'},parties:{id:'section-party',label:'Участники'},
  goods:{id:'section-goods',label:'Товары'},transport:{id:'section-transport',label:'Транспорт'},transports:{id:'section-transport',label:'Транспорт'},
  package:{id:'section-package',label:'Грузовые места'},packages:{id:'section-package',label:'Грузовые места'},document:{id:'section-document',label:'Документы'},documents:{id:'section-document',label:'Документы'},
  cost:{id:'section-cost',label:'Компоненты ДТС'},costs:{id:'section-cost',label:'Компоненты ДТС'},rate:{id:'section-rate',label:'Курсы валют'},rates:{id:'section-rate',label:'Курсы валют'},payment:{id:'section-payment',label:'Фактическая уплата'},payments:{id:'section-payment',label:'Фактическая уплата'},keden:{id:'section-keden',label:'Параметры XML'},
};
function issueAddress(issue:Issue,session:Session,registry:Registry):string {
  const [scope,id]=issue.address.split('/');
  if(issue.code==='COST_APPLICABILITY' && scope==='cost') {
    const cost=session.draft.costs.find(c=>c.id===id);const key=`${cost?.kind}Applicable`;
    if(registry.fields.fields.some(f=>f.id===`declaration.${key}`))return `declaration/_/${key}`;
  }
  return issue.address;
}
function issueLabel(address:string,session:Session,registry:Registry):string {
  const [scope,id,key]=address.split('/');const field=registry.fields.fields.find(f=>f.id===`${scope}.${key}`);
  let context=sections[scope]?.label || 'Проверка данных';
  const collection=registry.fields.scopes[scope]?.collection;
  if(collection && id) {
    const rows=session.draft[collection as keyof Session['draft']];
    if(Array.isArray(rows)) {
      const index=rows.findIndex(row=>row.id===id);
      if(index>=0)context=scope==='party'?roles[String(rows[index].role)] || context:scope==='document'?`${registry.fields.requiredDocuments?.find(required=>required.kind===rows[index].kind)?.label || 'Документ'} · ${rows[index].kind || index+1}`:`${context} · ${index+1}`;
    }
  }
  return field?`${context} · ${field.label}`:context;
}
export function focusReviewIssue(issue:Issue,session:Session,registry:Registry):void {
  const address=issueAddress(issue,session,registry);
  const [scope,entityId]=address.split('/');
  if(scope==='goods')window.dispatchEvent(new CustomEvent('customs-focus-good',{detail:entityId}));
  window.setTimeout(()=>{
  const target=document.getElementById(domId(address)) || document.getElementById(`graph-${issue.graphId??''}`) || document.getElementById(`calculation-goods-${entityId}`) || document.getElementById(sections[scope]?.id || 'section-declaration');
  if(!target)return;
  for(let node:HTMLElement|null=target;node;node=node.parentElement)if(node instanceof HTMLDetailsElement)node.open=true;
  if(!target.hasAttribute('tabindex') && !/^(INPUT|SELECT|TEXTAREA|BUTTON|A)$/.test(target.tagName))target.tabIndex=-1;
  target.scrollIntoView({behavior:'smooth',block:'center'});target.focus({preventScroll:true});
  },30);
}
export function ReviewValidation({session,registry}:{session:Session;registry:Registry}) {
  if(session.validation.valid)return null;
  const groups=new Map<string,{issue:Issue;messages:Set<string>}>();
  for(const issue of session.validation.errors) {
    const address=issueAddress(issue,session,registry);const group=groups.get(address) || {issue,messages:new Set<string>()};
    group.messages.add(issue.message);groups.set(address,group);
  }
  return <details className="validation-summary" open={session.validation.errors.length<=12}>
    <summary>Расчёт недоступен: ошибок — {session.validation.count}</summary>
    <p>Заполненные поля также проверяются на коды, даты, связи и подтверждения. Нажмите на причину, чтобы перейти к нужному месту.</p>
    {groups.size===0?<p>Сервер не вернул причины. Нажмите «Проверить заполнение», чтобы обновить результат.</p>:<ul>{[...groups].map(([address,group])=><li key={address}><button type="button" className="text-button" onClick={()=>focusReviewIssue(group.issue,session,registry)}>{issueLabel(address,session,registry)}</button>{[...group.messages].map(message=><p key={message}>{message}</p>)}</li>)}</ul>}
  </details>;
}
