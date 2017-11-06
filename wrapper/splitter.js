module.exports.split = (str, delim) => {
    var index = str.indexOf(delim);

    return [str.slice(0, index), str.slice(index + 1)];
};

//console.log(module.exports.splitStr("this is a test", " "));