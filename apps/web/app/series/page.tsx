'use client';
import {useEffect,useState} from 'react';
import {api,errorText,Page,Series} from '../../lib/api';
import {SeriesCards} from '../cards';
export default function SeriesIndex(){const [series,setSeries]=useState<Series[]>([]);const [error,setError]=useState('');const [page,setPage]=useState(1);const [last,setLast]=useState(1);const [loading,setLoading]=useState(true);
useEffect(()=>{const c=new AbortController();setLoading(true);setError('');api<Page<Series>>(`/series?page=${page}`,'',{signal:c.signal}).then(j=>{setSeries(j.data);setLast(j.meta.last_page);}).catch(e=>{if(!c.signal.aborted)setError(errorText(e));}).finally(()=>{if(!c.signal.aborted)setLoading(false);});return()=>c.abort();},[page]);
return <main><p className="eyebrow">EPISODIC STORYTELLING</p><h1>Series</h1>{error?<p role="alert" className="error">{error}</p>:loading?<p role="status">Loading series…</p>:series.length?<>
<SeriesCards series={series}/><nav className="pagination" aria-label="Series pages"><button disabled={page<=1||loading} onClick={()=>setPage(p=>p-1)}>Previous</button><span className="muted"> Page {page} of {last} </span><button disabled={page>=last||loading} onClick={()=>setPage(p=>p+1)}>Next</button></nav></>:<p className="muted">No series available yet.</p>}</main>;}
