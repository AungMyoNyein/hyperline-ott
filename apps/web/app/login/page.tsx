import Login from './sign-in';
export default async function LoginPage({searchParams}:{searchParams:Promise<{next?:string}>}){const {next}=await searchParams;return <Login next={next?.startsWith('/')&&!next.startsWith('//')&&!next.includes('\\')?next:'/'} />;}
