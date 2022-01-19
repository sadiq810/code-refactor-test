##Feedback
- I have reviewed the code, the structure is good and I like the repository pattern that you have implemented and keep the controller clean.
- Overall the code is looking good, but I would like to add few suggestions here to make the code more stable, readable and maintainable.

#Suggestions:
- Code is properly commented out, but a little details need to be added to the top of the function, so a new developer can quickly know that what is going on in this function. 
- There are plenty of variables that are defined without any needs, and you already have the same data in the parameter object/request to the function, So it is just a waste of memory to redefine a variable and assign a value which is already exist in the parameter.
- Try to use access/mutator or define methods on models to check some condition on a model i:e 
  ``$user->isCustomer()  or $user->isTranslator() etc.``
  That will make the code more readable and maintainable.
- Don't get all the fields from database and it's better to select only desirable fields and it will improve the performance.
- For counting rows or getting first row: don't get all the models and then apply desire action i.e: 
  ``User::get()->count() or User::get()->first() etc. `` instead use ``User::count() or User::first()`` and it will improve the performance.
- For looping through large dataset, use the Eloquent cursor instead of getting all the models and then run foreach loop on them. Cursor will drastically improve the performance and consume less memory.
- To avoid code explosion, use the try-catch for exception handling or use 'null safe operator' of php 8 feature for accessing item inside deep.
- Validate the data in the controller and then pass it to the repository to avoid errors and undesirable result.
- Avoid long if-else statement and use switch statement instead as it is faster,
- Avoid too long functions and instead write small function that perform specific task.
- Important thing in my opinion: use PHP 8+ and Laravel 8+  as the performance and security is largely enhanced in it.

##what i did
- Perform some refactoring, commenting, formatting and write some basic unit test and can be found in 'tests/' folder. TeHelperTest.php and UserRepositoryTest.php
<hr>
<hr>
<hr>
<hr>
Choose ONE of the following tasks.
Please do not invest more than 2-4 hours on this.
Upload your results to a Github repo, for easier sharing and reviewing.

Thank you and good luck!



Code to refactor
=================
1) app/Http/Controllers/BookingController.php
2) app/Repository/BookingRepository.php

Code to write tests
=====================
3) App/Helpers/TeHelper.php method willExpireAt
4) App/Repository/UserRepository.php, method createOrUpdate


----------------------------

What I expect in your repo:

X. A readme with:   Your thoughts about the code. What makes it amazing code. Or what makes it ok code. Or what makes it terrible code. How would you have done it. Thoughts on formatting, structure, logic.. The more details that you can provide about the code (what's terrible about it or/and what is good about it) the easier for us to assess your coding style, mentality etc

And 

Y.  Refactor it if you feel it needs refactoring. The more love you put into it. The easier for us to asses your thoughts, code principles etc


IMPORTANT: Make two commits. First commit with original code. Second with your refactor so we can easily trace changes. 


NB: you do not need to set up the code on local and make the web app run. It will not run as its not a complete web app. This is purely to assess you thoughts about code, formatting, logic etc


===== So expected output is a GitHub link with either =====

1. Readme described above (point X above) + refactored code 
OR
2. Readme described above (point X above) + a unit test of the code that we have sent

Thank you!


