import Player from './player';
export default async function Watch({searchParams}:{searchParams:Promise<{id?:string;episode?:string;series?:string;n?:string}>}){const p=await searchParams;return <main><p className="eyebrow">NOW SHOWING</p><h1>HyperLine player</h1><Player movieId={p.id||''} episodeId={p.episode||''} seriesSlug={p.series||''} episodeNumber={p.n||''}/></main>;}
