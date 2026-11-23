import type {Entity,Registry,Session} from './types';

// Missing forms are UI slots, not documentary facts or exportable records.
export function requiredDocumentRows(session:Session,registry:Registry):Entity[] {
  const rows:Entity[]=[];const used=new Set<string>();
  for(const required of registry.fields.requiredDocuments || []) {
    const existing=session.draft.documents.find(row=>row.kind===required.kind && !used.has(row.id));
    if(existing) {used.add(existing.id);rows.push({...existing,_displayKey:`required-${required.kind}`});continue;}
    const row:Entity={id:`document-required-${required.kind}`,_requiredPlaceholder:true,_displayKey:`required-${required.kind}`};
    for(const field of registry.fields.fields.filter(field=>field.scope==='document'))row[field.key]=field.fixedValue??(field.type.endsWith('-list')?[]:null);
    row.kind=required.kind;row.dateRequired=true;rows.push(row);
  }
  return [...rows,...session.draft.documents.filter(row=>!used.has(row.id))];
}
