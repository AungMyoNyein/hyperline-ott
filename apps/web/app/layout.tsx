import './style.css';
import SessionProvider from './session';
export const metadata = {title:'HyperLine OTT',description:'Discover movies on HyperLine.'};
export default function Layout({children}:{children:React.ReactNode}){return <html lang="en"><body><SessionProvider>{children}</SessionProvider></body></html>}
