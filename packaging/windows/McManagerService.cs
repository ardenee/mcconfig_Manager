// Local Windows Service for mcmanager's PHP worker (.NET Framework 4.x).
// Does not force-kill Minecraft. Actual game stdin lives in a per-server supervisor.
using System;
using System.IO;
using System.Collections.Generic;
using System.Diagnostics;
using System.ServiceProcess;
using System.Threading;
namespace McManagerWindows
{
    internal sealed class WorkerService : ServiceBase
    {
        readonly string php, workerScript, data, instances;
        readonly object gate=new object();
        Thread monitor;
        Process child;
        volatile bool stopping;
        public WorkerService(string config)
        {
            var settings=new Dictionary<string,string>(StringComparer.OrdinalIgnoreCase);
            foreach(string line in File.ReadAllLines(config))
            {
                if (line.Length==0||line.StartsWith("#"))continue;
                int at=line.IndexOf('=');
                if(at<1)throw new InvalidOperationException("Invalid worker service setting");
                settings[line.Substring(0,at)]=line.Substring(at+1);
            }
            string name=Get(settings,"service");
            if (!System.Text.RegularExpressions.Regex.IsMatch(name,"^[A-Za-z0-9-]{3,60}$"))
                throw new InvalidOperationException("Invalid Windows service name.");
            ServiceName=name; CanStop=true;CanShutdown=true;AutoLog=false;
            php=Path.GetFullPath(Get(settings,"php"));
            workerScript=Path.GetFullPath(Get(settings,"worker"));
            data=Path.GetFullPath(Get(settings,"data"));
            instances=Path.GetFullPath(Get(settings,"instances"));
            if(!File.Exists(php)||!File.Exists(workerScript)||!Directory.Exists(data)||
                !Directory.Exists(instances))
                throw new InvalidOperationException("PHP/worker or writable private directories missing.");
            foreach(var path in new[]{php,workerScript,data,instances})
                if(path.StartsWith(@"\\"))
                    throw new InvalidOperationException("The service only manages this local Windows host.");
        }
        static string Get(IDictionary<string,string> map,string name)
        {
            string value;
            if(!map.TryGetValue(name,out value)||String.IsNullOrWhiteSpace(value))
                throw new InvalidOperationException("Missing service setting: "+name);
            return value.Trim();
        }
        void Log(string message)
        {
            lock(gate)
            {
                string f=Path.Combine(data,@"manager\windows-service.log");
                Directory.CreateDirectory(Path.GetDirectoryName(f));
                string safe=message.Replace('\r',' ').Replace('\n',' ');
                if(safe.Length>600)safe=safe.Substring(0,600);
                File.AppendAllText(f,DateTime.UtcNow.ToString("o")+" "+safe+Environment.NewLine);
            }
        }
        protected override void OnStart(string[] args)
        {
            stopping=false;
            monitor=new Thread(Supervise){IsBackground=true,Name="mcmanager-worker-monitor"};
            monitor.Start();
        }
        void Supervise()
        {
            int failed=0;
            while(!stopping)
            {
                try
                {
                    var start=new ProcessStartInfo{
                        FileName=php,
                        Arguments="\""+workerScript.Replace("\"","\\\"")+"\" serve",
                        WorkingDirectory=Path.GetDirectoryName(workerScript),
                        UseShellExecute=false,CreateNoWindow=true,
                        RedirectStandardOutput=true,RedirectStandardError=true};
                    start.EnvironmentVariables["MCCONFIG_DATA_DIR"]=data;
                    start.EnvironmentVariables["MCMANAGER_INSTANCES_DIR"]=instances;
                    start.EnvironmentVariables["MCMANAGER_WINDOWS_SERVICE"]="1";
                    start.EnvironmentVariables["MCMANAGER_WINDOWS_SERVICE_NAME"]=ServiceName;
                    using(var proc=new Process{StartInfo=start,EnableRaisingEvents=true})
                    {
                        proc.OutputDataReceived+=(sender,e)=>{if(e.Data!=null)Log("worker stdout: "+e.Data);};
                        proc.ErrorDataReceived+=(sender,e)=>{if(e.Data!=null)Log("worker stderr: "+e.Data);};
                        if(!proc.Start())throw new InvalidOperationException("PHP worker launch failed.");
                        child=proc;
                        proc.BeginOutputReadLine();proc.BeginErrorReadLine();
                        Log("worker started PID="+proc.Id);
                        DateTime began=DateTime.UtcNow;
                        while(!proc.WaitForExit(500)&&!stopping){}
                        // On service stop, PHP may still be saving worlds. Wait
                        // without forcing an unsafe process termination.
                        if(stopping && !proc.HasExited)
                            while(!proc.WaitForExit(1000)){}
                        Log("worker exited code="+proc.ExitCode);
                        child=null;
                        if(stopping)break;
                        failed=(DateTime.UtcNow-began).TotalSeconds>60?0:Math.Min(5,failed+1);
                    }
                }
                catch(Exception e){
                    child=null;
                    Log("worker error: "+e.GetType().Name+" "+e.Message);
                    failed=Math.Min(5,failed+1);
                }
                int delay=Math.Min(30,2<<failed);
                for(int t=0;t<delay*2&&!stopping;t++)Thread.Sleep(500);
            }
        }
        void GracefulStop()
        {
            stopping=true;
            try
            {
                Process p=child;
                if(p==null||p.HasExited)return;
                string dir=Path.Combine(data,"manager");
                Directory.CreateDirectory(dir);
                File.WriteAllText(Path.Combine(dir,"stop-request"),"Windows Service stop");
                Log("service stop requested: waiting for Minecraft saves");
                for(int t=0;t<105;t++)
                {
                    if(p.WaitForExit(1000))break;
                    if(t%10==0)RequestAdditionalTime(15000);
                }
                if(!p.HasExited)Log("WARNING worker still shutting down; no process was force-killed.");
            }
            catch(Exception ex){Log("shutdown: "+ex.Message);}
        }
        protected override void OnStop(){GracefulStop();}
        protected override void OnShutdown(){GracefulStop();}
        public static int Main(string[] args)
        {
            try
            {
                if(args.Length!=2||args[0]!="--config")
                    throw new ArgumentException("Usage: McManagerService.exe --config <ini-file>");
                ServiceBase.Run(new WorkerService(Path.GetFullPath(args[1])));
                return 0;
            }
            catch(Exception e)
            {
                try{File.AppendAllText(Path.Combine(Path.GetTempPath(),"mcmanager-service-startup.log"),
                    DateTime.UtcNow.ToString("o")+" "+e.Message+Environment.NewLine);}
                catch{}
                return 1;
            }
        }
    }
}
