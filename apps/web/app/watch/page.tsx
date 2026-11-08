import Player from './player';
export default async function Watch({searchParams}:{searchParams:Promise<{id?:string}>}){const p=await searchParams;return <main><h1>HyperLine Player</h1><Player id={p.id||''}/></main>}
