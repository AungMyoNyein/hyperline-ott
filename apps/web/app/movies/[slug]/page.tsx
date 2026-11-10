import Detail from './detail';
export default async function MoviePage({params}:{params:Promise<{slug:string}>}){return <Detail slug={(await params).slug}/>;}
