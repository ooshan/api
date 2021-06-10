var app     =     require("express")();
var mysql   =     require("mysql");
var http    =     require('http').Server(app);
var io      =     require("socket.io")(http);

var pool    =    mysql.createPool({connectionLimit:100,host:'localhost',user:'salmaner',password:'2r*57Esv',database:'salmaner',debug:false});

io.on('connection',function(socket){
	
});


http.listen(3000,function(){});