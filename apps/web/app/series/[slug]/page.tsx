import Detail from './detail';
export default async function SeriesPage({params}:{params:Promise<{slug:string}>}){return <Detail slug={(await params).slug}/>;}
