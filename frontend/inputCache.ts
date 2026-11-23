let owner='';
const pending=new Map<string,string>();
export function pendingInput(session:string,address:string):string|undefined {
  if(owner!==session){owner=session;pending.clear();}
  return pending.get(address);
}
export function rememberInput(session:string,address:string,value:string):void {pendingInput(session,address);pending.set(address,value);}
export function forgetInput(session:string,address:string):void {if(owner===session)pending.delete(address);}
