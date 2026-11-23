import type {ReactNode} from 'react';
import type {Graph} from './types';

export function GraphCard({graph,child=false,children}:{graph:Graph;child?:boolean;children:ReactNode}) {
  const number=graph.id==='B'?'В':graph.id.replace('a','а');
  if(child)return <section id={`graph-${graph.id}`} data-graph-id={graph.id} className="graph-child" tabIndex={-1}><strong>Графа {number}</strong>{children}</section>;
  return <fieldset id={`graph-${graph.id}`} data-graph-id={graph.id} className={`graph-card compact-graph-card ${graph.fillMode==='not_applicable'?'graph-inactive':''}`} tabIndex={-1}>
    <legend>Графа {number}</legend>
    <div className="graph-card-body"><h2 id={`graph-title-${graph.id}`}>{graph.title}</h2><div className="graph-editor-scroll">{children}</div></div>
  </fieldset>;
}
