'use client';
import Link from 'next/link';
import {useEffect,useMemo,useState} from 'react';
import {api,errorText,ProgressRow,Season,Series} from '../../../lib/api';
import {useSession} from '../../session';
function watchHref(seriesSlug:string,episodeId:number,episodeNumber:number,token:string){
  const target=`/watch?episode=${episodeId}&series=${encodeURIComponent(seriesSlug)}&n=${episodeNumber}`;
  return token?target:`/login?next=${encodeURIComponent(target)}`;
}
function minutes(seconds:number|null){return seconds?`${Math.max(1,Math.round(seconds/60))} min`:null;}
function seasonLabel(s:Season){return s.season_number===0?'Specials':s.title?`Season ${s.season_number} — ${s.title}`:`Season ${s.season_number}`;}
type EpisodeProgress = {position:number;duration:number|null;completed:boolean};
export default function Detail({slug}:{slug:string}){
  const {token}=useSession();
  const [series,setSeries]=useState<Series|null>(null);
  const [error,setError]=useState('');
  const [progress,setProgress]=useState<Record<number,EpisodeProgress>>({});
  const [selectedSeason,setSelectedSeason]=useState<number|null>(null);
  useEffect(()=>{const c=new AbortController();setSeries(null);setError('');api<{data:Series}>(`/series/${encodeURIComponent(slug)}`,'',{signal:c.signal}).then(j=>{setSeries(j.data);setSelectedSeason(null);}).catch(e=>{if(!c.signal.aborted)setError(errorText(e));});return()=>c.abort();},[slug]);
  useEffect(()=>{
    if(!token)return;const c=new AbortController();
    api<{data:ProgressRow[]}>('/watch-progress',token,{signal:c.signal})
      .then(j=>{const map:Record<number,EpisodeProgress>={};for(const row of j.data){if(row.type==='episode'&&row.episode)map[row.episode.id]={position:row.position_seconds,duration:row.duration_seconds,completed:row.completed};}setProgress(map);})
      .catch(()=>{});
    return()=>c.abort();
  },[token,slug]);
  // Seasons come from the API in catalog order (main seasons ascending,
  // specials last). Fall back to a synthetic single season when absent.
  const seasons:Season[]=useMemo(()=>{
    if(!series)return[];
    if(series.seasons&&series.seasons.length)return series.seasons;
    return[{id:0,season_number:1,title:null,synopsis:null,poster_url:null,backdrop_url:null,episodes:series.episodes||[]}];
  },[series]);
  const seasonNumber=selectedSeason??seasons[0]?.season_number??null;
  const season=seasons.find(s=>s.season_number===seasonNumber)??seasons[0]??null;
  const episodes=season?.episodes||[];
  // Resume rule: the most recent incomplete episode across the whole series
  // (flat `episodes` list is server-ordered); otherwise season 1, episode 1.
  const resumeEpisode=useMemo(()=>{
    if(!series)return null;
    let best:{id:number;episode_number:number}|null=null;
    for(const ep of series.episodes||[]){const p=progress[ep.id];if(p&&!p.completed&&p.position>0){best=ep;break;}}
    return best;
  },[series,progress]);
  const firstEpisode=episodes[0]||null;
  const startEpisode=resumeEpisode&&episodes.some(e=>e.id===resumeEpisode.id)?resumeEpisode:firstEpisode;
  const progressOf=(episodeId:number)=>{
    const p=progress[episodeId];if(!p)return null;
    const total=p.duration||null;
    if(p.completed)return{percent:100,completed:true};
    if(!total||p.position<=0)return null;
    return{percent:Math.min(100,Math.max(2,Math.round((p.position/total)*100))),completed:false};
  };
  const backdrop=series?.backdrop_url||series?.poster_url;
  return <main>{error?<p role="alert" className="error">{error}</p>:!series?<p role="status">Loading series…</p>:<>
    <section className="detail-hero" style={backdrop?{backgroundImage:`url(${JSON.stringify(backdrop)})`}:undefined}>
      <div className="detail-hero-inner">
        {series.poster_url&&<img className="detail-poster" src={series.poster_url} alt={series.title}/>}
        <div className="detail-info">
          <p className="eyebrow">{series.featured?'FEATURED SERIES':'HYPERLINE SERIES'}</p>
          <h1>{series.title}</h1>
          <p className="detail-meta">
            <span>{[series.release_year,series.language,series.category==='vertical'?'Vertical series':series.category].filter(Boolean).join(' · ')||'Series'}</span>
            {series.season_count!=null&&<span>{series.season_count} season{series.season_count===1?'':'s'}</span>}
            {series.episode_count!=null&&<span>{series.episode_count} episode{series.episode_count===1?'':'s'}</span>}
          </p>
          <p className="synopsis">{series.synopsis||'A new story unfolds, episode by episode.'}</p>
          <div className="detail-actions">
            {startEpisode?
              <Link className="button" href={watchHref(series.slug,startEpisode.id,startEpisode.episode_number,token)}>
                {token
                  ?(resumeEpisode&&startEpisode.id===resumeEpisode.id?`Resume episode ${startEpisode.episode_number}`:`Watch episode ${startEpisode.episode_number}`)
                  :'Sign in to watch'}
              </Link>
              :<p className="muted">Playback is currently unavailable.</p>}
          </div>
        </div>
      </div>
    </section>
    <section>
      <div className="section-heading rail-head">
        <h2>Episodes</h2>
        {seasons.length>1&&<div className="season-select">
          <label htmlFor="season-picker">Season</label>
          <select id="season-picker" aria-label="Season" value={seasonNumber??undefined} onChange={e=>setSelectedSeason(Number(e.target.value))}>
            {seasons.map(s=><option key={s.id} value={s.season_number}>{seasonLabel(s)}</option>)}
          </select>
        </div>}
      </div>
      {season&&season.synopsis&&seasons.length>1&&<p className="muted">{season.synopsis}</p>}
      {!episodes.length?<p className="muted">No episodes published in this season yet.</p>:<ol className="episodes">{episodes.map(ep=>{
        const p=progressOf(ep.id);
        return <li key={ep.id}>
          {ep.still_url&&<img className={`ep-still${series.category==='vertical'?' vertical':''}`} src={ep.still_url} alt="" loading="lazy"/>}
          <span className="ep-num">E{ep.episode_number}</span>
          <div className="grow">
            <h3>{ep.title||`Episode ${ep.episode_number}`}{p?.completed&&<span className="muted" aria-label="Completed"> ✓</span>}</h3>
            {ep.synopsis&&<p className="muted ep-synopsis">{ep.synopsis}</p>}
            {ep.duration_seconds&&<p className="muted">{minutes(ep.duration_seconds)}</p>}
            {p&&!p.completed&&<div className="progress-track" role="progressbar" aria-valuenow={p.percent} aria-valuemin={0} aria-valuemax={100} aria-label={`${p.percent}% watched`}><i style={{width:`${p.percent}%`}}/></div>}
          </div>
          {ep.playable!==false?
            <Link className="button" href={watchHref(series.slug,ep.id,ep.episode_number,token)}>{token?(p&&!p.completed?'Resume':'Watch'):'Sign in'}</Link>
            :<span className="muted">Unavailable</span>}
        </li>;})}</ol>}
      <p className="muted faint">Episode order follows seasons ascending (specials last) and episode numbers within each season; “Next episode” in the player uses the same rule.</p>
    </section>
  </>}</main>;}
