import {useEffect,useId,useRef,useState,type ReactNode} from 'react';
import {createPortal} from 'react-dom';

export function SourcePopover({label,children,className='text-button',onSelect,disabled=false,pressed}:{label:ReactNode;children:ReactNode;className?:string;onSelect?:()=>void;disabled?:boolean;pressed?:boolean}) {
  const [open,setOpen]=useState(false);const [position,setPosition]=useState({left:12,top:12});const anchor=useRef<HTMLButtonElement>(null);const panel=useRef<HTMLDivElement>(null);const timer=useRef<ReturnType<typeof setTimeout>|null>(null);const id=useId();
  function cancel(){if(timer.current)clearTimeout(timer.current);}
  function close(){cancel();timer.current=setTimeout(()=>setOpen(false),150);}
  function place(){const r=anchor.current?.getBoundingClientRect();if(!r)return;const width=Math.min(480,innerWidth-24);setPosition({left:Math.max(12,Math.min(r.left,innerWidth-width-12)),top:Math.max(12,Math.min(r.bottom+6,innerHeight-240))});}
  function show(){cancel();place();setOpen(true);}
  useEffect(()=>{if(!open)return;const listener=()=>place();window.addEventListener('scroll',listener,true);window.addEventListener('resize',listener);return()=>{window.removeEventListener('scroll',listener,true);window.removeEventListener('resize',listener);};},[open]);
  useEffect(()=>()=>cancel(),[]);
  function blur(event:React.FocusEvent){if(panel.current?.contains(event.relatedTarget as Node)||anchor.current===event.relatedTarget)return;close();}
  return <><button ref={anchor} type="button" className={className} disabled={disabled} aria-pressed={pressed} aria-describedby={open?id:undefined} aria-expanded={open} onMouseEnter={show} onMouseLeave={close} onFocus={show} onBlur={blur} onKeyDown={e=>{if(e.key==='Escape')setOpen(false);}} onClick={()=>{if(onSelect)onSelect();else if(open)setOpen(false);else show();}}>{label}</button>
    {open&&createPortal(<div ref={panel} id={id} role="tooltip" className="source-tooltip floating-source" style={position} onMouseEnter={show} onMouseLeave={close} onBlur={blur} onKeyDown={e=>{if(e.key==='Escape'){setOpen(false);anchor.current?.focus();}}}>{children}</div>,document.body)}</>;
}
